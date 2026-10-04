<?php

namespace App\Models;

use App\Database;

/**
 * 余额流水
 *
 * 设计要点：
 *   - 任何余额变动都必须经过本类，保证"有账必有流水"，便于对账
 *   - 全程使用 bcmath 做十进制运算，绝不用浮点（0.1+0.2 !== 0.3）
 *   - credit/debit 内部对用户行加 FOR UPDATE 行锁，防止并发下余额错乱
 *   - 方法可在外部事务中安全调用（Database 的 transaction 会复用已开启的事务）
 *
 * 流水类型：
 *   redeem  兑换码充值
 *   consume 下单消费
 *   admin   管理员人工调账
 *   refund  退款回补
 */
class BalanceLog
{
    public const TYPE_REDEEM  = 'redeem';
    public const TYPE_CONSUME = 'consume';
    public const TYPE_ADMIN   = 'admin';
    public const TYPE_REFUND  = 'refund';

    /** 金额小数位（与 DECIMAL(10,2) 对齐） */
    public const SCALE = 2;

    /** 单次变动上限，防止误操作把余额改爆 */
    public const MAX_AMOUNT = '999999.99';

    /**
     * 入账（增加余额）
     *
     * @param string $amount 正数金额
     * @return string 变动后余额
     * @throws \RuntimeException 金额非法
     */
    public static function credit(
        int $userId,
        string $amount,
        string $type,
        int $refId = 0,
        string $remark = ''
    ): string {
        $amount = self::normalize($amount);
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new \RuntimeException('入账金额必须大于 0');
        }
        return self::apply($userId, $amount, $type, $refId, $remark);
    }

    /**
     * 出账（扣减余额）
     *
     * @param string $amount 正数金额
     * @return bool 余额不足返回 false（不产生任何变动）
     */
    public static function debit(
        int $userId,
        string $amount,
        string $type,
        int $refId = 0,
        string $remark = ''
    ): bool {
        $amount = self::normalize($amount);
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return false;
        }
        try {
            self::apply($userId, bcmul($amount, '-1', self::SCALE), $type, $refId, $remark);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * 判断余额是否充足
     */
    public static function hasEnough(int $userId, string $amount): bool
    {
        $balance = User::balanceOf($userId);
        return bccomp($balance, self::normalize($amount), self::SCALE) >= 0;
    }

    /**
     * 执行变动（核心）
     *
     * @param string $delta 带符号金额：正=入账，负=出账
     * @return string 变动后余额
     */
    private static function apply(
        int $userId,
        string $delta,
        string $type,
        int $refId,
        string $remark
    ): string {
        if ($userId <= 0) {
            throw new \RuntimeException('用户不存在');
        }
        if (bccomp(ltrim($delta, '-'), self::MAX_AMOUNT, self::SCALE) > 0) {
            throw new \RuntimeException('单次变动金额超出上限');
        }

        $db = Database::instance();

        // 行锁：同一用户的并发变动在此串行化
        $row = $db->first('SELECT id,balance FROM ly_users WHERE id=? FOR UPDATE', [$userId]);
        if (!$row) {
            throw new \RuntimeException('用户不存在');
        }

        $before = self::normalize((string)$row['balance']);
        $after  = bcadd($before, $delta, self::SCALE);

        if (bccomp($after, '0', self::SCALE) < 0) {
            // 余额不足：抛异常让调用方处理（若在事务中会整体回滚）
            throw new \RuntimeException('余额不足');
        }

        // 双保险：带 where 条件更新，rowCount 为 0 说明并发下已被改动
        $affected = $db->update(
            'ly_users',
            ['balance' => $after],
            'id=? AND `balance`=?',
            [$userId, $before]
        );
        if ($affected === 0) {
            throw new \RuntimeException('余额更新冲突，请重试');
        }

        $db->insert('ly_balance_logs', [
            'user_id' => $userId,
            'change'  => $delta,
            'before'  => $before,
            'after'   => $after,
            'type'    => $type,
            'ref_id'  => $refId,
            'remark'  => mb_substr($remark, 0, 240),
        ]);

        // Auth::user() 有静态缓存，这里主动失效以免同请求内读到旧余额
        User::flushCache();

        return $after;
    }

    /**
     * 用户余额流水（分页）
     */
    public static function forUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $db = Database::instance();
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=?', [$userId]);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT * FROM ly_balance_logs WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            [$userId]
        );
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * 后台流水查询（分页，支持按用户/类型筛选）
     */
    public static function paginate(int $page, int $perPage = 20, array $filter = []): array
    {
        $db = Database::instance();
        $where = '1=1';
        $params = [];

        if (!empty($filter['user_id'])) {
            $where .= ' AND l.user_id=?';
            $params[] = (int)$filter['user_id'];
        }
        if (!empty($filter['type'])) {
            $where .= ' AND l.type=?';
            $params[] = (string)$filter['type'];
        }
        if (!empty($filter['keyword'])) {
            $where .= ' AND (u.email LIKE ? OR u.nickname LIKE ?)';
            $kw = '%' . $filter['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        $total = (int)$db->value(
            'SELECT COUNT(*) FROM ly_balance_logs l LEFT JOIN ly_users u ON u.id=l.user_id WHERE ' . $where,
            $params
        );
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT l.*, u.email AS user_email, u.nickname AS user_nickname
             FROM ly_balance_logs l LEFT JOIN ly_users u ON u.id=l.user_id
             WHERE ' . $where . ' ORDER BY l.id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            $params
        );
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * 统计
     */
    public static function stats(): array
    {
        $db = Database::instance();
        return [
            'issued_total'  => (string)$db->value(
                "SELECT IFNULL(SUM(`change`),0) FROM ly_balance_logs WHERE `change`>0 AND type=?", [self::TYPE_REDEEM]
            ),
            'consumed_total' => (string)$db->value(
                "SELECT IFNULL(ABS(SUM(`change`)),0) FROM ly_balance_logs WHERE `change`<0 AND type=?", [self::TYPE_CONSUME]
            ),
            'user_balance_total' => (string)$db->value('SELECT IFNULL(SUM(balance),0) FROM ly_users'),
            'log_total'     => (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs'),
        ];
    }

    /**
     * 单个用户的累计统计（前台余额页用）
     */
    public static function statsForUser(int $userId): array
    {
        $db = Database::instance();
        return [
            'recharged_total' => (string)$db->value(
                "SELECT IFNULL(SUM(`change`),0) FROM ly_balance_logs
                 WHERE user_id=? AND `change`>0 AND type IN (?,?)",
                [$userId, self::TYPE_REDEEM, self::TYPE_ADMIN]
            ),
            'consumed_total' => (string)$db->value(
                "SELECT IFNULL(ABS(SUM(`change`)),0) FROM ly_balance_logs
                 WHERE user_id=? AND `change`<0 AND type=?",
                [$userId, self::TYPE_CONSUME]
            ),
            'refunded_total' => (string)$db->value(
                "SELECT IFNULL(SUM(`change`),0) FROM ly_balance_logs
                 WHERE user_id=? AND `change`>0 AND type=?",
                [$userId, self::TYPE_REFUND]
            ),
            'log_total' => (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=?', [$userId]),
        ];
    }

    /**
     * 金额归一化：转成两位小数字符串
     * 非法输入返回 '0.00'
     */
    public static function normalize($amount): string
    {
        if (!is_scalar($amount)) {
            return '0.00';
        }
        $s = trim((string)$amount);
        if ($s === '' || !is_numeric($s)) {
            return '0.00';
        }
        return bcadd($s, '0', self::SCALE);
    }

    /**
     * 校验金额是否合法（正数、不超上限）
     * @return string 错误信息，'' 表示合法
     */
    public static function validateAmount($amount, string $min = '0.01', string $max = self::MAX_AMOUNT): string
    {
        if (!is_scalar($amount)) {
            return '金额格式不正确';
        }
        $s = trim((string)$amount);
        if ($s === '' || !is_numeric($s)) {
            return '金额格式不正确';
        }
        $v = bcadd($s, '0', self::SCALE);
        if (bccomp($v, $min, self::SCALE) < 0) {
            return '金额不能小于 ' . money($min) . ' 元';
        }
        if (bccomp($v, $max, self::SCALE) > 0) {
            return '金额不能大于 ' . money($max) . ' 元';
        }
        return '';
    }
}
