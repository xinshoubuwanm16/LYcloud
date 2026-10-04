<?php

namespace App\Models;

use App\Database;

/**
 * 用户优惠券（券包实例）
 *
 * 与「兑换码」的关键差异：兑换码是一次性的（used_by 直接嵌主表），
 * 优惠券需要「多人各自限次」，因此拆成两部分：
 *   - ly_coupons     ：券模板（规则定义）
 *   - ly_user_coupons：用户持有的券实例（每人可持多张、可多次使用）
 *
 * 每人限次：统计该用户持有的该券实例数（发放/领取时校验 <= per_user_limit）
 * 并发安全：用券走 FOR UPDATE 行锁 + 条件 UPDATE（status=0 → 1），杜绝一券多用
 *
 * 状态：0 未使用 / 1 已使用 / 2 已失效
 */
class UserCoupon
{
    public const STATUS_UNUSED  = 0;
    public const STATUS_USED    = 1;
    public const STATUS_EXPIRED = 2;

    public const SOURCE_ADMIN = 'admin';  // 后台定向发放
    public const SOURCE_CLAIM = 'claim';  // 前台自主领取

    /** 单次发放的用户数上限 */
    public const MAX_GRANT = 200;

    // ============================================================
    // 发放 / 领取
    // ============================================================

    /**
     * 统计某用户持有某券的实例数（用于每人限次判定）
     */
    public static function countForUser(int $couponId, int $userId): int
    {
        return (int)Database::instance()->value(
            'SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=? AND user_id=?',
            [$couponId, $userId]
        );
    }

    /**
     * 后台定向发放（可一次发给多人）
     *
     * 每人限次规则：若该用户已持有 >= per_user_limit 张，则跳过并计入 skipped。
     *
     * @param int[]  $userIds 目标用户
     * @param string $source  admin / claim
     * @return array{ok:bool,msg:string,granted:int,skipped:int}
     */
    public static function grant(int $couponId, array $userIds, string $source = self::SOURCE_ADMIN): array
    {
        $coupon = Coupon::find($couponId);
        if (!$coupon) {
            return ['ok' => false, 'msg' => '优惠券不存在', 'granted' => 0, 'skipped' => 0];
        }

        // 去重 + 仅保留正整数
        $ids = [];
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0 && !in_array($uid, $ids, true)) {
                $ids[] = $uid;
            }
        }
        if (!$ids) {
            return ['ok' => false, 'msg' => '请至少选择一个用户', 'granted' => 0, 'skipped' => 0];
        }
        if (count($ids) > self::MAX_GRANT) {
            return ['ok' => false, 'msg' => '单次最多发放给 ' . self::MAX_GRANT . ' 个用户', 'granted' => 0, 'skipped' => 0];
        }

        $perLimit = max(1, (int)$coupon['per_user_limit']);
        // 发放总量（0=不限）：发到上限即停止，剩余计入 skipped
        $receivedLimit = (int)$coupon['received_limit'];
        $receivedCount = (int)$coupon['received_count'];
        $granted = 0;
        $skipped = 0;

        foreach ($ids as $uid) {
            // 总量封顶
            if ($receivedLimit > 0 && $receivedCount >= $receivedLimit) {
                $skipped++;
                continue;
            }
            // 用户必须存在
            if (!User::find($uid)) {
                $skipped++;
                continue;
            }
            // 每人限次
            if (self::countForUser($couponId, $uid) >= $perLimit) {
                $skipped++;
                continue;
            }
            try {
                self::insertInstance($coupon, $uid, $source);
                $granted++;
                $receivedCount++;
            } catch (\Throwable $e) {
                log_write('coupon_grant_error', '发放失败：' . $e->getMessage(), [
                    'coupon_id' => $couponId,
                    'user_id'   => $uid,
                ]);
                $skipped++;
            }
        }

        if ($granted > 0) {
            log_write('coupon_grant', sprintf('发放优惠券 #%d 给 %d 人（跳过 %d 人）', $couponId, $granted, $skipped));
        }

        return [
            'ok'      => $granted > 0,
            'msg'     => $granted > 0
                ? sprintf('已发放给 %d 位用户%s', $granted, $skipped > 0 ? "，{$skipped} 位已达限领次数被跳过" : '')
                : '没有用户被发放（可能均已达限领次数）',
            'granted' => $granted,
            'skipped' => $skipped,
        ];
    }

    /**
     * 前台自主领取（领券中心）
     *
     * 事务内：行锁券模板 → 校验可领 + 时间窗 + 总量 + 每人限次 → 插入实例 → received_count+1
     *
     * @return array{ok:bool,msg:string}
     */
    public static function claim(int $couponId, int $userId): array
    {
        $db = Database::instance();

        try {
            return $db->transaction(function () use ($db, $couponId, $userId) {
                // 行锁券模板，防止并发超发
                $coupon = $db->first('SELECT * FROM ly_coupons WHERE id=? FOR UPDATE', [$couponId]);
                if (!$coupon) {
                    return ['ok' => false, 'msg' => '优惠券不存在'];
                }
                if ((int)$coupon['claimable'] !== 1) {
                    return ['ok' => false, 'msg' => '该优惠券不支持自主领取'];
                }
                if ((int)$coupon['status'] !== Coupon::STATUS_ON) {
                    return ['ok' => false, 'msg' => '该优惠券已停用'];
                }
                if (!Coupon::isWithinWindow($coupon)) {
                    return ['ok' => false, 'msg' => '该优惠券不在活动时间内'];
                }

                // 发放总量限制（0=不限）
                // 持有行锁，$coupon['received_count'] 即权威值，无需二次查询
                $receivedLimit = (int)$coupon['received_limit'];
                if ($receivedLimit > 0 && (int)$coupon['received_count'] >= $receivedLimit) {
                    return ['ok' => false, 'msg' => '该优惠券已被领完'];
                }

                // 每人限次
                $perLimit = max(1, (int)$coupon['per_user_limit']);
                if (self::countForUser($couponId, $userId) >= $perLimit) {
                    return ['ok' => false, 'msg' => '您已领取过该优惠券（每人限领 ' . $perLimit . ' 张）'];
                }

                // insertInstance 内部会把 received_count +1
                self::insertInstance($coupon, $userId, self::SOURCE_CLAIM);

                log_write('coupon_claim', '用户 #' . $userId . ' 领取优惠券 #' . $couponId);
                return ['ok' => true, 'msg' => '领取成功，已放入「我的优惠券」'];
            });
        } catch (\Throwable $e) {
            log_write('coupon_claim_error', '领取优惠券失败：' . $e->getMessage(), [
                'coupon_id' => $couponId,
                'user_id'   => $userId,
            ]);
            return ['ok' => false, 'msg' => '领取失败，请稍后重试'];
        }
    }

    // ============================================================
    // 使用 / 回退（事务内调用）
    // ============================================================

    /**
     * 标记券已使用（条件 UPDATE：仅 0未使用 → 1已使用）
     * 调用方需保证已在订单事务内、且已通过 FOR UPDATE 锁过该行
     */
    public static function markUsed(int $id, int $orderId): bool
    {
        $n = Database::instance()->update(
            'ly_user_coupons',
            [
                'status'   => self::STATUS_USED,
                'order_id' => $orderId,
                'used_at'  => date('Y-m-d H:i:s'),
            ],
            'id=? AND status=?',
            [$id, self::STATUS_UNUSED]
        );
        return $n > 0;
    }

    /**
     * 关单时退回券（1已使用 → 0未使用），并把券的 used_count 减 1
     */
    public static function revertByOrder(int $orderId): bool
    {
        $db  = Database::instance();
        $row = $db->first('SELECT * FROM ly_user_coupons WHERE order_id=? AND status=? LIMIT 1', [
            $orderId,
            self::STATUS_USED,
        ]);
        if (!$row) {
            return false;
        }

        $n = $db->update(
            'ly_user_coupons',
            ['status' => self::STATUS_UNUSED, 'order_id' => 0, 'used_at' => null],
            'id=? AND status=?',
            [(int)$row['id'], self::STATUS_USED]
        );
        if ($n === 0) {
            return false;
        }

        $db->query(
            'UPDATE ly_coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id=?',
            [(int)$row['coupon_id']]
        );

        log_write('coupon_revert', '订单 #' . $orderId . ' 关闭，退回优惠券实例 #' . $row['id']);
        return true;
    }

    // ============================================================
    // 查询
    // ============================================================

    public static function find(int $id): ?array
    {
        return Database::instance()->first(
            'SELECT uc.*, c.name AS coupon_name, c.type, c.value, c.min_amount, c.max_discount,
                    c.scope, c.per_user_limit
               FROM ly_user_coupons uc
               LEFT JOIN ly_coupons c ON c.id = uc.coupon_id
              WHERE uc.id=? LIMIT 1',
            [$id]
        ) ?: null;
    }

    /**
     * 某用户的券包（可带状态筛选）
     *
     * @param int|null $status null=全部
     * @return array{total:int,rows:array,page:int,perPage:int,pages:int}
     */
    public static function forUser(int $userId, ?int $status = null, int $page = 1, int $perPage = 20): array
    {
        $db = Database::instance();
        $where  = 'uc.user_id=?';
        $params = [$userId];

        if ($status !== null) {
            // 「已失效」需把过期但状态仍为未使用的也纳入
            if ($status === self::STATUS_EXPIRED) {
                $where .= ' AND (uc.status=2 OR (uc.status=0 AND uc.expires_at IS NOT NULL AND uc.expires_at < NOW()))';
            } else {
                $where .= ' AND uc.status=?';
                if ($status === self::STATUS_UNUSED) {
                    $where .= ' AND (uc.expires_at IS NULL OR uc.expires_at >= NOW())';
                }
                $params[] = $status;
            }
        }

        $total = (int)$db->value(
            'SELECT COUNT(*) FROM ly_user_coupons uc WHERE ' . $where,
            $params
        );

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = $db->select(
            'SELECT uc.*, c.name AS coupon_name, c.type, c.value, c.min_amount, c.max_discount,
                    c.scope, c.per_user_limit
               FROM ly_user_coupons uc
               LEFT JOIN ly_coupons c ON c.id = uc.coupon_id
              WHERE ' . $where . '
              ORDER BY uc.status ASC, uc.id DESC
              LIMIT ' . $perPage . ' OFFSET ' . $offset,
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

    /**
     * 结算时：某用户对某商品 + 某金额「可用」的券列表（含试算好的减免额）
     *
     * @return array<int,array> 每项含 user_coupon_id / coupon_id / name / discount / describe
     */
    public static function usableForProduct(int $userId, int $productId, string $amount): array
    {
        $rows = Database::instance()->select(
            'SELECT uc.id AS user_coupon_id, uc.coupon_id, uc.expires_at AS uc_expires,
                    c.*
               FROM ly_user_coupons uc
               JOIN ly_coupons c ON c.id = uc.coupon_id
              WHERE uc.user_id=? AND uc.status=0
                AND (uc.expires_at IS NULL OR uc.expires_at >= NOW())
                AND c.status=1
                AND (c.start_at IS NULL OR c.start_at <= NOW())
                AND (c.expires_at IS NULL OR c.expires_at >= NOW())
              ORDER BY c.id DESC',
            [$userId]
        );

        $out = [];
        foreach ($rows as $r) {
            if (!Coupon::isApplicableToProduct($r, $productId)) {
                continue;
            }
            $discount = Coupon::calcDiscount($r, $amount);
            if (bccomp($discount, '0', Coupon::SCALE) <= 0) {
                continue; // 未达门槛，不展示为可用
            }
            $out[] = [
                'user_coupon_id' => (int)$r['user_coupon_id'],
                'coupon_id'      => (int)$r['coupon_id'],
                'name'           => $r['name'],
                'type'           => $r['type'],
                'discount'       => $discount,
                'describe'       => Coupon::describe($r),
                'expires_at'     => $r['uc_expires'],
            ];
        }
        return $out;
    }

    /**
     * 领券中心：当前用户可领取的券
     *
     * @return array 每项追加 can_claim(bool) 与已领张数
     */
    public static function claimable(int $userId, int $page = 1, int $perPage = 20): array
    {
        $db = Database::instance();

        $total = (int)$db->value(
            "SELECT COUNT(*) FROM ly_coupons
              WHERE claimable=1 AND status=1
                AND (start_at IS NULL OR start_at <= NOW())
                AND (expires_at IS NULL OR expires_at >= NOW())
                AND (received_limit=0 OR received_count < received_limit)"
        );

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = $db->select(
            "SELECT * FROM ly_coupons
              WHERE claimable=1 AND status=1
                AND (start_at IS NULL OR start_at <= NOW())
                AND (expires_at IS NULL OR expires_at >= NOW())
                AND (received_limit=0 OR received_count < received_limit)
              ORDER BY id DESC
              LIMIT " . $perPage . ' OFFSET ' . $offset
        );

        foreach ($rows as &$r) {
            $mine = self::countForUser((int)$r['id'], $userId);
            $perLimit = max(1, (int)$r['per_user_limit']);
            $r['my_count']   = $mine;
            $r['can_claim']  = $mine < $perLimit;
            $r['remain']     = (int)$r['received_limit'] > 0
                ? max(0, (int)$r['received_limit'] - (int)$r['received_count'])
                : -1; // -1 = 不限量
        }
        unset($r);

        return [
            'total'   => $total,
            'rows'    => $rows,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => (int)max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * 某券的持券人列表（后台）
     *
     * @return array{total:int,rows:array,page:int,perPage:int,pages:int}
     */
    public static function holders(int $couponId, int $page = 1, int $perPage = 20): array
    {
        $db = Database::instance();
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$couponId]);

        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = $db->select(
            'SELECT uc.*, u.email, u.nickname, o.order_no
               FROM ly_user_coupons uc
               LEFT JOIN ly_users  u ON u.id = uc.user_id
               LEFT JOIN ly_orders o ON o.id = uc.order_id
              WHERE uc.coupon_id=?
              ORDER BY uc.id DESC
              LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$couponId]
        );

        return [
            'total'   => $total,
            'rows'    => $rows,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => (int)max(1, ceil($total / $perPage)),
        ];
    }

    /** 统计（后台） */
    public static function stats(): array
    {
        $db = Database::instance();
        return [
            'held_total'   => (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons'),
            'held_unused'  => (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE status=0'),
            'held_used'    => (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE status=1'),
            'held_expired' => (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE status=2'),
        ];
    }

    /** 用户本人对某券的持有张数（供前台展示） */
    public static function myCount(int $couponId, int $userId): int
    {
        return self::countForUser($couponId, $userId);
    }

    /** 单个用户的券包统计（前台「我的优惠券」顶部） */
    public static function statsForUser(int $userId): array
    {
        $db = Database::instance();
        return [
            'unused' => (int)$db->value(
                'SELECT COUNT(*) FROM ly_user_coupons
                  WHERE user_id=? AND status=0 AND (expires_at IS NULL OR expires_at >= NOW())',
                [$userId]
            ),
            'used' => (int)$db->value(
                'SELECT COUNT(*) FROM ly_user_coupons WHERE user_id=? AND status=1',
                [$userId]
            ),
            'expired' => (int)$db->value(
                'SELECT COUNT(*) FROM ly_user_coupons
                  WHERE user_id=? AND (status=2 OR (status=0 AND expires_at IS NOT NULL AND expires_at < NOW()))',
                [$userId]
            ),
            'saved' => BalanceLog::normalize((string)$db->value(
                'SELECT IFNULL(SUM(o.coupon_discount),0)
                   FROM ly_orders o
                   JOIN ly_user_coupons uc ON uc.order_id = o.id
                  WHERE o.user_id=? AND o.coupon_id > 0 AND o.status IN (1,2)',
                [$userId]
            )),
        ];
    }

    // ============================================================
    // 内部工具
    // ============================================================

    /**
     * 插入一条券实例（过期时间快照自券模板），并累加券模板的 received_count
     *
     * received_count 统计「累计发放张数」，后台定向发放与前台自主领取都必须计入，
     * 否则后台发放的券不会出现在发放量里，也无法约束 received_limit。
     */
    private static function insertInstance(array $coupon, int $userId, string $source): int
    {
        $db = Database::instance();
        $id = $db->insert('ly_user_coupons', [
            'coupon_id'  => (int)$coupon['id'],
            'user_id'    => $userId,
            'source'     => $source === self::SOURCE_CLAIM ? self::SOURCE_CLAIM : self::SOURCE_ADMIN,
            'status'     => self::STATUS_UNUSED,
            'order_id'   => 0,
            'expires_at' => !empty($coupon['expires_at']) ? $coupon['expires_at'] : null,
        ]);

        $db->query('UPDATE ly_coupons SET received_count = received_count + 1 WHERE id=?', [(int)$coupon['id']]);

        return $id;
    }
}
