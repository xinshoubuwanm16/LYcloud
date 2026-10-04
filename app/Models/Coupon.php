<?php

namespace App\Models;

use App\Database;

/**
 * 优惠券模板
 *
 * 设计要点：
 *   - 两种优惠类型：reduce（满减，value=减免金额）/ discount（折扣，value=折扣率如 0.85）
 *   - 适用范围：0 全场通用 / 1 指定商品（适用商品存 ly_coupon_scopes）
 *   - 每人限次：per_user_limit，通过统计该用户的 ly_user_coupons 条数控制
 *   - 领券中心：claimable=1 时前台可自领，received_limit 控制总发放量（0=不限）
 *   - 金额计算全程 bcmath 十进制，杜绝浮点误差
 *   - 减免额绝不超过订单金额（下界兜底）
 *
 * 状态：1 启用 / 0 停用
 */
class Coupon
{
    public const TYPE_REDUCE   = 'reduce';    // 满减券
    public const TYPE_DISCOUNT = 'discount';  // 折扣券

    public const SCOPE_ALL     = 0;  // 全场通用
    public const SCOPE_PRODUCT = 1;  // 指定商品

    public const STATUS_OFF = 0;
    public const STATUS_ON  = 1;

    /** 折扣券折扣率允许范围（0.01 ~ 0.99，即 1 折 ~ 99 折） */
    public const MIN_RATE = '0.01';
    public const MAX_RATE = '0.99';

    /** 金额精度 */
    public const SCALE = 2;

    /** 单项金额上限 */
    public const MAX_AMOUNT = '999999.99';

    /** 每人限次上限 */
    public const MAX_PER_USER = 99;

    // ============================================================
    // 校验（纯函数，不落库；成功返回 ''，失败返回错误文案）
    // ============================================================

    /** 归一化优惠类型 */
    public static function normalizeType(string $type): string
    {
        return $type === self::TYPE_DISCOUNT ? self::TYPE_DISCOUNT : self::TYPE_REDUCE;
    }

    /**
     * 校验建券/改券的输入
     *
     * @param array $data 表单数据：name,type,value,min_amount,max_discount,scope,per_user_limit,received_limit,claimable,status,start_at,expires_at,code
     * @param int[] $productIds scope=1 时的适用商品
     * @return string ''=合法，否则错误文案
     */
    public static function validate(array $data, array $productIds = []): string
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return '请填写优惠券名称';
        }
        if (mb_strlen($name) > 80) {
            return '优惠券名称不能超过 80 个字';
        }

        $type = self::normalizeType((string)($data['type'] ?? ''));
        $value = BalanceLog::normalize($data['value'] ?? '0');

        if ($type === self::TYPE_REDUCE) {
            if (bccomp($value, '0', self::SCALE) <= 0) {
                return '满减金额必须大于 0';
            }
            if (bccomp($value, self::MAX_AMOUNT, self::SCALE) > 0) {
                return '满减金额不能超过 ' . self::MAX_AMOUNT;
            }
        } else {
            // 折扣券：value 是折扣率，必须落在 (0,1)
            if (bccomp($value, '0', self::SCALE) <= 0) {
                return '折扣率必须大于 0';
            }
            if (bccomp($value, '1', self::SCALE) >= 0) {
                return '折扣率必须小于 1（如 0.85 表示 85 折）';
            }
            if (bccomp($value, self::MIN_RATE, self::SCALE) < 0) {
                return '折扣率不能低于 ' . self::MIN_RATE . '（1 折）';
            }
        }

        $minAmount = BalanceLog::normalize($data['min_amount'] ?? '0');
        if (bccomp($minAmount, '0', self::SCALE) < 0) {
            return '使用门槛不能为负数';
        }
        if (bccomp($minAmount, self::MAX_AMOUNT, self::SCALE) > 0) {
            return '使用门槛不能超过 ' . self::MAX_AMOUNT;
        }
        // 满减券：门槛必须 >= 减免额，否则会白送钱
        if ($type === self::TYPE_REDUCE && bccomp($minAmount, $value, self::SCALE) < 0) {
            return '使用门槛不能低于满减金额（否则将出现 0 元购）';
        }

        $maxDiscount = BalanceLog::normalize($data['max_discount'] ?? '0');
        if (bccomp($maxDiscount, '0', self::SCALE) < 0) {
            return '折扣上限不能为负数';
        }
        if (bccomp($maxDiscount, self::MAX_AMOUNT, self::SCALE) > 0) {
            return '折扣上限不能超过 ' . self::MAX_AMOUNT;
        }

        $perUser = (int)($data['per_user_limit'] ?? 1);
        if ($perUser < 1 || $perUser > self::MAX_PER_USER) {
            return '每人限用次数需在 1 ~ ' . self::MAX_PER_USER . ' 之间';
        }

        $recvLimit = (int)($data['received_limit'] ?? 0);
        if ($recvLimit < 0 || $recvLimit > 999999) {
            return '发放总量需在 0 ~ 999999 之间（0 表示不限）';
        }

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code !== '' && !preg_match('/^[A-Z0-9]{4,20}$/', $code)) {
            return '券码只能包含 4~20 位字母或数字';
        }

        $scope = (int)($data['scope'] ?? self::SCOPE_ALL);
        if (!in_array($scope, [self::SCOPE_ALL, self::SCOPE_PRODUCT], true)) {
            return '适用范围取值不合法';
        }
        if ($scope === self::SCOPE_PRODUCT && count($productIds) === 0) {
            return '选择「指定商品」时，请至少勾选一个商品';
        }

        $startAt   = trim((string)($data['start_at'] ?? ''));
        $expiresAt = trim((string)($data['expires_at'] ?? ''));
        if ($startAt !== '' && strtotime($startAt) === false) {
            return '生效时间格式不正确';
        }
        if ($expiresAt !== '' && strtotime($expiresAt) === false) {
            return '失效时间格式不正确';
        }
        if ($startAt !== '' && $expiresAt !== '' && strtotime($startAt) > strtotime($expiresAt)) {
            return '生效时间不能晚于失效时间';
        }

        return '';
    }

    // ============================================================
    // 试算与适用范围判定（供下单链路调用，纯函数不落库）
    // ============================================================

    /**
     * 计算优惠券对给定订单金额的减免额
     *
     * 规则：
     *   - 订单金额 < min_amount 时返回 '0.00'（不满足门槛）
     *   - reduce  ：减免 min(value, amount)
     *   - discount：减免 = amount × (1 - value)，即 value=0.85 时打 85 折；
     *               若 max_discount > 0 则封顶
     *   - 最终一定满足 0 <= 减免 <= amount
     *
     * @param array  $coupon 券模板行
     * @param string $amount 订单金额（优惠前）
     * @return string 减免金额，'0.00' 表示不适用
     */
    public static function calcDiscount(array $coupon, string $amount): string
    {
        $total = BalanceLog::normalize($amount);
        if (bccomp($total, '0', self::SCALE) <= 0) {
            return '0.00';
        }

        $minAmount = BalanceLog::normalize($coupon['min_amount'] ?? '0');
        if (bccomp($total, $minAmount, self::SCALE) < 0) {
            return '0.00';
        }

        $type  = self::normalizeType((string)($coupon['type'] ?? ''));
        $value = BalanceLog::normalize($coupon['value'] ?? '0');

        if ($type === self::TYPE_REDUCE) {
            $discount = $value;
        } else {
            // 折扣率转减免：amount - amount×rate
            $payPart  = bcmul($total, $value, self::SCALE);   // 折后应付
            $discount = bcsub($total, $payPart, self::SCALE); // 减免额
        }

        // 折扣上限封顶
        $maxDiscount = BalanceLog::normalize($coupon['max_discount'] ?? '0');
        if (bccomp($maxDiscount, '0', self::SCALE) > 0 && bccomp($discount, $maxDiscount, self::SCALE) > 0) {
            $discount = $maxDiscount;
        }

        // 下界兜底：减免不能超过订单金额，也不能为负
        if (bccomp($discount, $total, self::SCALE) > 0) {
            $discount = $total;
        }
        if (bccomp($discount, '0', self::SCALE) < 0) {
            $discount = '0.00';
        }

        return $discount;
    }

    /** 券是否在有效时间窗内 */
    public static function isWithinWindow(array $coupon): bool
    {
        $now = time();
        $startAt   = $coupon['start_at']   ?? null;
        $expiresAt = $coupon['expires_at'] ?? null;

        if (!empty($startAt) && strtotime((string)$startAt) > $now) {
            return false;
        }
        if (!empty($expiresAt) && strtotime((string)$expiresAt) < $now) {
            return false;
        }
        return true;
    }

    /** 券是否适用于指定商品 */
    public static function isApplicableToProduct(array $coupon, int $productId): bool
    {
        if ((int)($coupon['scope'] ?? self::SCOPE_ALL) === self::SCOPE_ALL) {
            return true;
        }
        return in_array($productId, self::scopeProductIds((int)$coupon['id']), true);
    }

    /** 券当前是否可用于下单（启用 + 在时间窗内） */
    public static function isUsable(array $coupon): bool
    {
        if ((int)($coupon['status'] ?? self::STATUS_OFF) !== self::STATUS_ON) {
            return false;
        }
        return self::isWithinWindow($coupon);
    }

    /** 人类可读的优惠描述，如「满 100.00 减 20.00」/「85 折（最高减 50.00）」 */
    public static function describe(array $coupon): string
    {
        $type    = self::normalizeType((string)($coupon['type'] ?? ''));
        $value   = BalanceLog::normalize($coupon['value'] ?? '0');
        $minAmt  = BalanceLog::normalize($coupon['min_amount'] ?? '0');
        $maxDisc = BalanceLog::normalize($coupon['max_discount'] ?? '0');

        if ($type === self::TYPE_REDUCE) {
            $text = '减 ¥' . money($value);
        } else {
            // 0.85 → 85 折；0.9 → 9 折；0.95 → 9.5 折
            $rateNum = bcmul($value, '10', 2);          // 8.50
            $rateNum = rtrim(rtrim($rateNum, '0'), '.'); // 8.5
            $text = $rateNum . ' 折';
            if (bccomp($maxDisc, '0', self::SCALE) > 0) {
                $text .= '（最高减 ¥' . money($maxDisc) . '）';
            }
        }

        if (bccomp($minAmt, '0', self::SCALE) > 0) {
            return '满 ¥' . money($minAmt) . ' ' . $text;
        }
        return '无门槛 ' . $text;
    }

    /** 简短类型标签 */
    public static function typeLabel(array $coupon): string
    {
        return self::normalizeType((string)($coupon['type'] ?? '')) === self::TYPE_DISCOUNT
            ? '折扣券'
            : '满减券';
    }

    // ============================================================
    // 增删改
    // ============================================================

    /**
     * 创建优惠券
     *
     * @param array $data       表单数据
     * @param int[] $productIds 适用商品（scope=1 时）
     * @param int   $adminId    操作管理员
     * @return array{ok:bool,msg:string,id:int}
     */
    public static function create(array $data, array $productIds, int $adminId): array
    {
        $productIds = self::cleanProductIds($productIds);
        $err = self::validate($data, $productIds);
        if ($err !== '') {
            return ['ok' => false, 'msg' => $err, 'id' => 0];
        }

        $db = Database::instance();
        $code = strtoupper(trim((string)($data['code'] ?? '')));

        // 券码唯一性（非空时）
        if ($code !== '' && self::findByCode($code)) {
            return ['ok' => false, 'msg' => '该券码已被占用，请换一个', 'id' => 0];
        }
        // 空券码用 NULL 语义：库里存空串会撞唯一索引，这里生成占位
        $storedCode = $code !== '' ? $code : self::placeholderCode();

        try {
            $id = $db->transaction(function () use ($db, $data, $productIds, $adminId, $storedCode) {
                $couponId = $db->insert('ly_coupons', [
                    'name'           => trim((string)$data['name']),
                    'code'           => $storedCode,
                    'type'           => self::normalizeType((string)$data['type']),
                    'value'          => BalanceLog::normalize($data['value'] ?? '0'),
                    'min_amount'     => BalanceLog::normalize($data['min_amount'] ?? '0'),
                    'max_discount'   => BalanceLog::normalize($data['max_discount'] ?? '0'),
                    'scope'          => (int)($data['scope'] ?? self::SCOPE_ALL),
                    'per_user_limit' => (int)($data['per_user_limit'] ?? 1),
                    'received_limit' => (int)($data['received_limit'] ?? 0),
                    'claimable'      => (int)($data['claimable'] ?? 0) === 1 ? 1 : 0,
                    'status'         => (int)($data['status'] ?? self::STATUS_ON) === self::STATUS_OFF
                                            ? self::STATUS_OFF : self::STATUS_ON,
                    'start_at'       => self::nullableDate($data['start_at'] ?? ''),
                    'expires_at'     => self::nullableDate($data['expires_at'] ?? ''),
                    'remark'         => mb_substr(trim((string)($data['remark'] ?? '')), 0, 255),
                    'created_by'     => $adminId,
                ]);

                self::saveScopes($db, $couponId, $productIds, (int)($data['scope'] ?? self::SCOPE_ALL));
                return $couponId;
            });
        } catch (\Throwable $e) {
            log_write('coupon_create_error', '创建优惠券失败：' . $e->getMessage());
            return ['ok' => false, 'msg' => '创建失败，请稍后重试', 'id' => 0];
        }

        log_write('coupon_create', '创建优惠券 #' . $id . '：' . $data['name'], [
            'admin_id' => $adminId,
            'scope'    => (int)($data['scope'] ?? 0),
        ]);

        return ['ok' => true, 'msg' => '优惠券创建成功', 'id' => $id];
    }

    /**
     * 更新优惠券
     *
     * @return array{ok:bool,msg:string}
     */
    public static function update(int $id, array $data, array $productIds): array
    {
        $existing = self::find($id);
        if (!$existing) {
            return ['ok' => false, 'msg' => '优惠券不存在'];
        }

        $productIds = self::cleanProductIds($productIds);
        $err = self::validate($data, $productIds);
        if ($err !== '') {
            return ['ok' => false, 'msg' => $err];
        }

        $db = Database::instance();
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code !== '' && $code !== $existing['code'] && self::findByCode($code)) {
            return ['ok' => false, 'msg' => '该券码已被占用，请换一个'];
        }
        $storedCode = $code !== '' ? $code : self::placeholderCode();

        try {
            $db->transaction(function () use ($db, $id, $data, $productIds, $storedCode) {
                $db->update('ly_coupons', [
                    'name'           => trim((string)$data['name']),
                    'code'           => $storedCode,
                    'type'           => self::normalizeType((string)$data['type']),
                    'value'          => BalanceLog::normalize($data['value'] ?? '0'),
                    'min_amount'     => BalanceLog::normalize($data['min_amount'] ?? '0'),
                    'max_discount'   => BalanceLog::normalize($data['max_discount'] ?? '0'),
                    'scope'          => (int)($data['scope'] ?? self::SCOPE_ALL),
                    'per_user_limit' => (int)($data['per_user_limit'] ?? 1),
                    'received_limit' => (int)($data['received_limit'] ?? 0),
                    'claimable'      => (int)($data['claimable'] ?? 0) === 1 ? 1 : 0,
                    'status'         => (int)($data['status'] ?? self::STATUS_ON) === self::STATUS_OFF
                                            ? self::STATUS_OFF : self::STATUS_ON,
                    'start_at'       => self::nullableDate($data['start_at'] ?? ''),
                    'expires_at'     => self::nullableDate($data['expires_at'] ?? ''),
                    'remark'         => mb_substr(trim((string)($data['remark'] ?? '')), 0, 255),
                ], 'id=?', [$id]);

                $db->delete('ly_coupon_scopes', 'coupon_id=?', [$id]);
                self::saveScopes($db, $id, $productIds, (int)($data['scope'] ?? self::SCOPE_ALL));
            });
        } catch (\Throwable $e) {
            log_write('coupon_update_error', '更新优惠券失败：' . $e->getMessage());
            return ['ok' => false, 'msg' => '保存失败，请稍后重试'];
        }

        log_write('coupon_update', '更新优惠券 #' . $id, ['admin_id' => \App\Auth::adminId()]);
        return ['ok' => true, 'msg' => '优惠券已保存'];
    }

    /** 启用 / 停用 */
    public static function setStatus(int $id, int $status): bool
    {
        $status = $status === self::STATUS_ON ? self::STATUS_ON : self::STATUS_OFF;
        $n = Database::instance()->update('ly_coupons', ['status' => $status], 'id=?', [$id]);
        return $n > 0;
    }

    /**
     * 尝试使用 FORCE 参数删除时的二次确认口令（前后端共用同一字面量）
     */
    public const FORCE_CONFIRM_WORD = '永久删除';

    /** 券持有/使用情况概览（列表页展示与删除前置检查共用，数值实时） */
    public static function usage(int $id): array
    {
        $db = Database::instance();
        return [
            // 券包中仍「未使用且未过期」的券（删除后这些券将全部收回）
            'available' => (int)$db->value(
                'SELECT COUNT(*) FROM ly_user_coupons
                  WHERE coupon_id=? AND status=0 AND (expires_at IS NULL OR expires_at >= NOW())',
                [$id]
            ),
            // 券包总实例数（含已使用、已失效）
            'held' => (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$id]),
            // 被订单引用的笔数
            'used' => (int)$db->value(
                'SELECT COUNT(*) FROM ly_orders WHERE coupon_id=? AND coupon_id > 0',
                [$id]
            ),
        ];
    }

    /**
     * 删除优惠券
     *
     * 默认（安全模式）：券包中还有「未使用的券」时拒绝删除，提示改用「停用」。
     *   已使用/已失效的券包记录与历史订单不构成阻塞——券模板删除后：
     *     · 券包查询走 LEFT JOIN，券名回退为快照，不会报错或空白
     *     · 订单自己保存了 coupon_discount 金额快照，历史记录完整可查
     *
     * 强制模式（$force=true，后台需输入确认口令）：
     *   收回用户手中所有未使用的券（删除券包实例，仅限 status=0 且未过期，
     *   已使用的券保留以维持用户券包历史），同时清理适用范围表；不可恢复。
     */
    public static function delete(int $id, bool $force = false): array
    {
        $db = Database::instance();
        $coupon = self::find($id);
        if (!$coupon) {
            return ['ok' => false, 'msg' => '优惠券不存在或已被删除'];
        }

        $u = self::usage($id);
        // 订单中的券名是历史快照（order 表未存券名，此处为订单商品名兜底文案）
        $name = (string)$coupon['name'];

        if (!$force && $u['available'] > 0) {
            return [
                'ok'  => false,
                'msg' => '该券还有 ' . $u['available'] . ' 张在用户手中未使用，无法删除。'
                    . '可改为「停用」（不影响已持有的券）；'
                    . '确实要删除时请使用「强制删除」，并输入确认口令「' . self::FORCE_CONFIRM_WORD . '」。',
            ];
        }

        $revoked = 0;
        $db->transaction(function () use ($db, $id, $force, &$revoked) {
            if ($force) {
                // 收回未使用的券（已使用的保留：用户券包历史与订单对账需要）
                $revoked = $db->delete(
                    'ly_user_coupons',
                    'coupon_id=? AND status=0 AND (expires_at IS NULL OR expires_at >= NOW())',
                    [$id]
                );
                // 未使用但已过期的券一并清理（无保留价值）
                $db->delete(
                    'ly_user_coupons',
                    'coupon_id=? AND status=0 AND expires_at IS NOT NULL AND expires_at < NOW()',
                    [$id]
                );
            }
            $db->delete('ly_coupon_scopes', 'coupon_id=?', [$id]);
            $db->delete('ly_coupons', 'id=?', [$id]);
        });

        $msg = $force
            ? '优惠券「' . $name . '」已强制删除'
                . ($revoked > 0 ? '，收回用户手中未使用的 ' . $revoked . ' 张券' : '')
                . ($u['used'] > 0 ? '；历史 ' . $u['used'] . ' 笔订单的优惠金额快照不受影响' : '')
            : '优惠券「' . $name . '」已删除';

        log_write('coupon_delete', $msg, [
            'admin_id' => \App\Auth::adminId(),
            'coupon_id' => $id,
            'force'     => $force ? 1 : 0,
            'revoked'   => $revoked,
            'held'      => $u['held'],
            'used'      => $u['used'],
        ]);

        return ['ok' => true, 'msg' => $msg, 'revoked' => $revoked];
    }

    // ============================================================
    // 查询
    // ============================================================

    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_coupons WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        return Database::instance()->first('SELECT * FROM ly_coupons WHERE code=? LIMIT 1', [$code]) ?: null;
    }

    /**
     * 后台列表分页
     *
     * @param array $filter keyword/type/status/scope/claimable
     * @return array{total:int,rows:array,page:int,perPage:int,pages:int}
     */
    public static function paginate(int $page, int $perPage = 20, array $filter = []): array
    {
        $db  = Database::instance();
        $where  = [];
        $params = [];

        $kw = trim((string)($filter['keyword'] ?? ''));
        if ($kw !== '') {
            $where[]  = '(name LIKE ? OR code LIKE ? OR remark LIKE ?)';
            $like     = '%' . $kw . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $type = trim((string)($filter['type'] ?? ''));
        if ($type !== '') {
            $where[]  = 'type = ?';
            $params[] = self::normalizeType($type);
        }

        if (isset($filter['status']) && $filter['status'] !== '' && $filter['status'] !== null) {
            $where[]  = 'status = ?';
            $params[] = (int)$filter['status'];
        }

        if (isset($filter['scope']) && $filter['scope'] !== '' && $filter['scope'] !== null) {
            $where[]  = 'scope = ?';
            $params[] = (int)$filter['scope'];
        }

        if (!empty($filter['claimable'])) {
            $where[] = 'claimable = 1';
        }

        $sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_coupons' . $sqlWhere, $params);

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = $db->select(
            'SELECT * FROM ly_coupons' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'total'   => $total,
            'rows'    => $rows,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => (int)max(1, ceil($total / $perPage)),
        ];
    }

    /** 指定券的适用商品 ID 数组 */
    public static function scopeProductIds(int $couponId): array
    {
        $rows = Database::instance()->select(
            'SELECT product_id FROM ly_coupon_scopes WHERE coupon_id=?',
            [$couponId]
        );
        return array_map('intval', array_column($rows, 'product_id'));
    }

    /** 指定券的适用商品名称列表（后台展示用） */
    public static function scopeProductNames(int $couponId): array
    {
        $rows = Database::instance()->select(
            'SELECT p.name FROM ly_coupon_scopes s
               JOIN ly_products p ON p.id = s.product_id
              WHERE s.coupon_id=?',
            [$couponId]
        );
        return array_column($rows, 'name');
    }

    /** 全部上架商品（供后台多选渲染） */
    public static function productOptions(): array
    {
        return Database::instance()->select(
            'SELECT id,name,price,status FROM ly_products ORDER BY status DESC, id ASC'
        );
    }

    /** 统计（后台列表页顶部） */
    public static function stats(): array
    {
        $db = Database::instance();
        return [
            'total'      => (int)$db->value('SELECT COUNT(*) FROM ly_coupons'),
            'active'     => (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE status=1'),
            'claimable'  => (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE claimable=1 AND status=1'),
            'held'       => (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE status=0'),
            'used'       => (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE status=1'),
            'discounted' => BalanceLog::normalize(
                (string)$db->value('SELECT IFNULL(SUM(coupon_discount),0) FROM ly_orders WHERE coupon_id>0')
            ),
        ];
    }

    // ============================================================
    // 内部工具
    // ============================================================

    /** 清洗商品 ID 数组：去重、仅保留正整数 */
    private static function cleanProductIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** 写入适用商品（scope=1 时） */
    private static function saveScopes(Database $db, int $couponId, array $productIds, int $scope): void
    {
        if ($scope !== self::SCOPE_PRODUCT) {
            return;
        }
        foreach ($productIds as $pid) {
            $db->insert('ly_coupon_scopes', [
                'coupon_id'  => $couponId,
                'product_id' => $pid,
            ]);
        }
    }

    /** 空日期转 null */
    private static function nullableDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /** 无公开券码时生成占位码，避免空串撞唯一索引 */
    private static function placeholderCode(): string
    {
        return '__' . bin2hex(random_bytes(8));
    }
}
