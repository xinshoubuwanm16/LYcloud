<?php

namespace App\Models;

use App\Database;

/**
 * 余额兑换码
 *
 * 安全设计：
 *   - 库中只存 sha256(码)，不存明文；明文仅在 generate() 返回值中出现一次
 *   - 码用 random_int 生成，字符集剔除易混字符（0/O/1/I），降低人工抄错率
 *   - code_hash 有唯一索引，插入冲突自动重试
 *   - 兑换走 FOR UPDATE 行锁 + 状态二次判定，杜绝并发重复兑换
 *   - 支持面额与有效期；有效期为空表示永久有效
 *
 * 状态：0 未用 / 1 已用 / 2 已作废
 */
class RedeemCode
{
    public const STATUS_UNUSED = 0;
    public const STATUS_USED   = 1;
    public const STATUS_VOID   = 2;

    /** 易混字符已剔除：0 O 1 I（保留 L，因 L 与数字 2~9 不混淆） */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** 随机部分长度（前缀 LY 之外） */
    private const BODY_LEN = 12;

    /** 前缀 */
    private const PREFIX = 'LY';

    /** 单批最大生成数量 */
    public const MAX_BATCH = 1000;

    /** 插入冲突重试次数 */
    private const MAX_RETRY = 5;

    // ============================================================
    // 生成
    // ============================================================

    /**
     * 批量生成兑换码
     *
     * @param int         $count   生成数量
     * @param string      $amount  单张面额
     * @param int|null    $ttlDays 有效天数，null 或 <=0 表示永久有效
     * @param int         $adminId 操作管理员
     * @param string      $remark  备注
     * @return array{ok:bool,msg:string,batch_no:string,codes:string[],amount:string}
     */
    public static function generate(
        int $count,
        string $amount,
        ?int $ttlDays,
        int $adminId,
        string $remark = ''
    ): array {
        $count  = (int)$count;
        $amount = BalanceLog::normalize($amount);

        if ($count < 1) {
            return ['ok' => false, 'msg' => '生成数量至少为 1', 'batch_no' => '', 'codes' => [], 'amount' => $amount];
        }
        if ($count > self::MAX_BATCH) {
            return ['ok' => false, 'msg' => '单批最多生成 ' . self::MAX_BATCH . ' 个', 'batch_no' => '', 'codes' => [], 'amount' => $amount];
        }
        if (bccomp($amount, '0', BalanceLog::SCALE) <= 0) {
            return ['ok' => false, 'msg' => '面额必须大于 0', 'batch_no' => '', 'codes' => [], 'amount' => $amount];
        }

        $db       = Database::instance();
        $batchNo  = 'B' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $expiresAt = null;
        if ($ttlDays !== null && $ttlDays > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + $ttlDays * 86400);
        }

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = self::createOne($db, $batchNo, $amount, $expiresAt, $adminId, $remark);
            if ($raw === null) {
                // 极端情况下连续冲突，放弃本批剩余部分
                break;
            }
            $codes[] = $raw;
        }

        if (!$codes) {
            return ['ok' => false, 'msg' => '生成失败，请重试', 'batch_no' => '', 'codes' => [], 'amount' => $amount];
        }

        $msg = '成功生成 ' . count($codes) . ' 个兑换码';
        if (count($codes) < $count) {
            $msg .= '（原计划 ' . $count . ' 个，因唯一性冲突提前结束）';
        }

        return ['ok' => true, 'msg' => $msg, 'batch_no' => $batchNo, 'codes' => $codes, 'amount' => $amount];
    }

    /**
     * 生成并落库一个码，返回明文（失败返回 null）
     */
    private static function createOne(
        Database $db,
        string $batchNo,
        string $amount,
        ?string $expiresAt,
        int $adminId,
        string $remark
    ): ?string {
        for ($try = 0; $try < self::MAX_RETRY; $try++) {
            $raw  = self::randomCode();
            $hash = hash('sha256', $raw);

            // 先查重（唯一索引是最终保障，这里只为减少异常）
            $exists = $db->first('SELECT id FROM ly_redeem_codes WHERE code_hash=? LIMIT 1', [$hash]);
            if ($exists) {
                continue;
            }

            try {
                $db->insert('ly_redeem_codes', [
                    'code_hash'  => $hash,
                    'code_mask'  => self::mask($raw),
                    'batch_no'   => $batchNo,
                    'amount'     => $amount,
                    'status'     => self::STATUS_UNUSED,
                    'expires_at' => $expiresAt,
                    'remark'     => mb_substr($remark, 0, 240),
                    'created_by' => $adminId,
                ]);
                return $raw;
            } catch (\Throwable $e) {
                // 唯一索引冲突 → 重试
                continue;
            }
        }
        return null;
    }

    /**
     * 生成一个明文码：LY + 12 位随机字符
     */
    public static function randomCode(): string
    {
        $len      = strlen(self::ALPHABET);
        $body     = '';
        for ($i = 0; $i < self::BODY_LEN; $i++) {
            $body .= self::ALPHABET[random_int(0, $len - 1)];
        }
        return self::PREFIX . $body;
    }

    /**
     * 掩码：LY7K****4TP9 —— 保留前 4 位与后 4 位
     */
    public static function mask(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        $len = strlen($raw);
        if ($len <= 8) {
            return $raw;
        }
        return substr($raw, 0, 4) . '****' . substr($raw, -4);
    }

    /**
     * 码格式是否合法（前缀 + 长度 + 字符集）
     * 用户输入先过这一关，非法直接返回，不做库查询
     */
    public static function isValidFormat(string $raw): bool
    {
        $raw = strtoupper(trim($raw));
        if (strlen($raw) !== strlen(self::PREFIX) + self::BODY_LEN) {
            return false;
        }
        if (strpos($raw, self::PREFIX) !== 0) {
            return false;
        }
        $body = substr($raw, strlen(self::PREFIX));
        return strspn($body, self::ALPHABET) === strlen($body);
    }

    // ============================================================
    // 兑换
    // ============================================================

    /**
     * 兑换：把码换成余额
     *
     * 并发安全：整个流程在一个事务内，先 SELECT ... FOR UPDATE 锁住码所在行，
     *           再判定状态，最后标记已用 + 加余额 + 写流水。
     *
     * @return array{ok:bool,msg:string,amount:string,batch_no:string,code_id:int,after:string}
     */
    public static function redeem(string $rawCode, int $userId): array
    {
        $fail = static function (string $msg): array {
            return ['ok' => false, 'msg' => $msg, 'amount' => '0.00', 'batch_no' => '', 'code_id' => 0, 'after' => ''];
        };

        $rawCode = strtoupper(trim($rawCode));
        if ($rawCode === '') {
            return $fail('请输入兑换码');
        }
        // 格式不符直接拒绝，不消耗一次库查询（也避免无意义的限流计数）
        if (!self::isValidFormat($rawCode)) {
            return $fail('兑换码格式不正确，请检查后重新输入');
        }
        if ($userId <= 0) {
            return $fail('请先登录');
        }

        $db   = Database::instance();
        $hash = hash('sha256', $rawCode);

        try {
            return $db->transaction(function () use ($db, $hash, $userId, $rawCode, $fail) {
                // 行锁：同一码的并发兑换在此串行化
                $row = $db->first('SELECT * FROM ly_redeem_codes WHERE code_hash=? FOR UPDATE', [$hash]);
                if (!$row) {
                    return $fail('兑换码不存在，请核对后重试');
                }
                if ((int)$row['status'] === self::STATUS_USED) {
                    return $fail('该兑换码已被使用');
                }
                if ((int)$row['status'] === self::STATUS_VOID) {
                    return $fail('该兑换码已被作废');
                }
                if ($row['expires_at'] !== null && strtotime((string)$row['expires_at']) < time()) {
                    return $fail('该兑换码已过期');
                }

                $amount = BalanceLog::normalize((string)$row['amount']);
                if (bccomp($amount, '0', BalanceLog::SCALE) <= 0) {
                    return $fail('该兑换码面额异常，请联系客服');
                }

                // 标记已用（带 status 条件双保险）
                $affected = $db->update(
                    'ly_redeem_codes',
                    [
                        'status'  => self::STATUS_USED,
                        'used_by' => $userId,
                        'used_at' => date('Y-m-d H:i:s'),
                    ],
                    'id=? AND status=?',
                    [(int)$row['id'], self::STATUS_UNUSED]
                );
                if ($affected === 0) {
                    return $fail('该兑换码已被使用');
                }

                // 加余额 + 写流水（BalanceLog 内部同样带行锁与条件更新）
                $after = BalanceLog::credit(
                    $userId,
                    $amount,
                    BalanceLog::TYPE_REDEEM,
                    (int)$row['id'],
                    '兑换码充值 ' . self::mask($rawCode)
                );

                return [
                    'ok'       => true,
                    'msg'      => '兑换成功，已充值 ¥' . money($amount),
                    'amount'   => $amount,
                    'batch_no' => (string)$row['batch_no'],
                    'code_id'  => (int)$row['id'],
                    'after'    => $after,
                ];
            });
        } catch (\Throwable $e) {
            log_write('redeem_error', '兑换异常：' . $e->getMessage(), ['user_id' => $userId]);
            return $fail('兑换失败，请稍后重试');
        }
    }

    // ============================================================
    // 查询与运维
    // ============================================================

    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_redeem_codes WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    /**
     * 后台列表（分页）
     * 注意：只返回掩码，不含明文
     */
    public static function paginate(int $page, int $perPage = 20, array $filter = []): array
    {
        $db = Database::instance();
        $where = '1=1';
        $params = [];

        if (isset($filter['status']) && $filter['status'] !== '' && $filter['status'] !== null) {
            $where .= ' AND status=?';
            $params[] = (int)$filter['status'];
        }
        if (!empty($filter['batch_no'])) {
            $where .= ' AND batch_no=?';
            $params[] = (string)$filter['batch_no'];
        }
        if (!empty($filter['keyword'])) {
            // 掩码模糊匹配；若用户填的是完整码，转 hash 精确匹配
            $kw = strtoupper(trim((string)$filter['keyword']));
            if (self::isValidFormat($kw)) {
                $where .= ' AND code_hash=?';
                $params[] = hash('sha256', $kw);
            } else {
                $where .= ' AND code_mask LIKE ?';
                $params[] = '%' . $kw . '%';
            }
        }

        $total = (int)$db->value('SELECT COUNT(*) FROM ly_redeem_codes WHERE ' . $where, $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT * FROM ly_redeem_codes WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            $params
        );
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * 按批次列出（用于批次详情/导出）
     */
    public static function byBatch(string $batchNo, int $limit = 1000): array
    {
        return Database::instance()->select(
            'SELECT * FROM ly_redeem_codes WHERE batch_no=? ORDER BY id ASC LIMIT ' . (int)$limit,
            [$batchNo]
        );
    }

    /**
     * 作废单个（仅未用的可作废）
     */
    public static function void(int $id): bool
    {
        $affected = Database::instance()->update(
            'ly_redeem_codes',
            ['status' => self::STATUS_VOID],
            'id=? AND status=?',
            [$id, self::STATUS_UNUSED]
        );
        return $affected > 0;
    }

    /**
     * 作废整批中所有未用的码
     * @return int 作废数量
     */
    public static function voidBatch(string $batchNo): int
    {
        return Database::instance()->update(
            'ly_redeem_codes',
            ['status' => self::STATUS_VOID],
            'batch_no=? AND status=?',
            [$batchNo, self::STATUS_UNUSED]
        );
    }

    /**
     * 清理过期未用的码：标记为已作废
     * @return int 处理数量
     */
    public static function expireOutdated(): int
    {
        return Database::instance()->update(
            'ly_redeem_codes',
            ['status' => self::STATUS_VOID],
            'status=? AND expires_at IS NOT NULL AND expires_at < NOW()',
            [self::STATUS_UNUSED]
        );
    }

    /**
     * 统计
     */
    public static function stats(): array
    {
        $db = Database::instance();
        return [
            'total'      => (int)$db->value('SELECT COUNT(*) FROM ly_redeem_codes'),
            'unused'     => (int)$db->value('SELECT COUNT(*) FROM ly_redeem_codes WHERE status=?', [self::STATUS_UNUSED]),
            'used'       => (int)$db->value('SELECT COUNT(*) FROM ly_redeem_codes WHERE status=?', [self::STATUS_USED]),
            'void'       => (int)$db->value('SELECT COUNT(*) FROM ly_redeem_codes WHERE status=?', [self::STATUS_VOID]),
            'issued_amount' => (string)$db->value(
                'SELECT IFNULL(SUM(amount),0) FROM ly_redeem_codes WHERE status=?',
                [self::STATUS_USED]
            ),
        ];
    }

    /**
     * 最近批次（生成页展示用）
     */
    public static function recentBatches(int $limit = 10): array
    {
        return Database::instance()->select(
            'SELECT batch_no, amount, COUNT(*) AS total,
                    SUM(CASE WHEN status=0 THEN 1 ELSE 0 END) AS unused,
                    SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) AS used,
                    SUM(CASE WHEN status=2 THEN 1 ELSE 0 END) AS void_cnt,
                    MIN(created_at) AS created_at
             FROM ly_redeem_codes
             GROUP BY batch_no, amount
             ORDER BY MIN(id) DESC LIMIT ' . (int)$limit
        );
    }
}
