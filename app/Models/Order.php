<?php

namespace App\Models;

use App\Database;

/**
 * 订单模型
 */
class Order
{
    public const STATUS_PENDING   = 0;  // 待支付
    public const STATUS_PAID      = 1;  // 已支付
    public const STATUS_DELIVERED = 2;  // 已发货
    public const STATUS_CLOSED    = 3;  // 已关闭
    public const STATUS_REFUNDED  = 4;  // 已退款

    /** 金额精度 */
    public const SCALE = 2;

    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_orders WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    public static function findByNo(string $orderNo): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_orders WHERE order_no=? LIMIT 1', [$orderNo]) ?: null;
    }

    public static function findByTradeNo(string $tradeNo): ?array
    {
        if ($tradeNo === '') {
            return null;
        }
        return Database::instance()->first('SELECT * FROM ly_orders WHERE trade_no=? LIMIT 1', [$tradeNo]) ?: null;
    }

    public static function findForUser(int $id, int $userId): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM ly_orders WHERE id=? AND user_id=? LIMIT 1',
            [$id, $userId]
        ) ?: null;
    }

    /**
     * 创建订单（含并发安全的库存占用 + 可选的优惠券抵扣 + 可选的余额抵扣）
     *
     * 金额链（恒等式）：
     *   amount（订单总额）
     *     = coupon_discount（优惠券减免）
     *     + balance_paid（余额抵扣）
     *     + pay_amount（需支付宝实付）
     *
     * 抵扣顺序：先券后余额 —— 券以「订单总额」为基数试算，
     * 余额再以「券后应付」为基数抵扣，避免券把余额的抵扣空间算错。
     *
     * @param bool     $useBalance   是否使用余额抵扣（true 时尽可能抵扣，不足部分转在线支付）
     * @param int|null $userCouponId 用户选用的券实例 ID（ly_user_coupons.id），null/0 表示不用券
     * @throws \RuntimeException 库存不足 / 商品下架 / 券不可用
     */
    public static function createWithStock(
        array $user,
        array $product,
        string $channel,
        bool $useBalance = false,
        ?int $userCouponId = null
    ): array {
        $db = Database::instance();
        $orderNo = random_order_no();
        $userCouponId = (int)$userCouponId > 0 ? (int)$userCouponId : null;

        $orderId = $db->transaction(function () use ($db, $user, $product, $channel, $orderNo, $useBalance, $userCouponId) {
            // 乐观检查：商品仍上架
            // FOR UPDATE 锁住商品行：同商品的下单请求在此串行化，
            // 保证下面的「每人限购」校验不会被并发绕过（REPEATABLE READ 下普通读会读到同一快照）
            $p = $db->first('SELECT * FROM ly_products WHERE id=? AND status=1 LIMIT 1 FOR UPDATE', [$product['id']]);
            if (!$p) {
                throw new \RuntimeException('商品已下架，请选择其他商品');
            }

            $userId = (int)$user['id'];

            // ---- 每人限购校验（在锁内，先于库存分配，避免白占库存）----
            $quota = Product::canPurchase($p, $userId);
            if (!$quota['ok']) {
                throw new \RuntimeException($quota['msg']);
            }

            $stockId = 0;
            // MNBT 实时开通的商品不占用本站库存池（主机由 MNBT 现开），
            // 因此跳过库存分配，避免「MNBT 商品被误判缺货而无法下单」。
            if ((int)$p['stock_mode'] === 1 && !Product::isMnbt($p)) {
                $stock = Stock::allocate((int)$p['id']);
                if (!$stock) {
                    throw new \RuntimeException('该商品库存不足，请等待补货或联系客服');
                }
                $stockId = (int)$stock['id'];
            }

            $total         = BalanceLog::normalize($p['price']);
            $couponId      = 0;
            $couponDiscount = '0.00';

            // ---- 第一步：优惠券抵扣（先券）----
            $afterCoupon = $total;
            if ($userCouponId !== null && Setting::couponEnabled()) {
                // 行锁券实例，防止并发重复核销
                $uc = $db->first('SELECT * FROM ly_user_coupons WHERE id=? FOR UPDATE', [$userCouponId]);
                if (!$uc) {
                    throw new \RuntimeException('优惠券不存在，请重新选择');
                }
                if ((int)$uc['user_id'] !== $userId) {
                    throw new \RuntimeException('该优惠券不属于当前账号');
                }
                if ((int)$uc['status'] !== UserCoupon::STATUS_UNUSED) {
                    throw new \RuntimeException('该优惠券已被使用或已失效');
                }
                if (!empty($uc['expires_at']) && strtotime((string)$uc['expires_at']) < time()) {
                    throw new \RuntimeException('该优惠券已过期');
                }

                $coupon = Coupon::find((int)$uc['coupon_id']);
                if (!$coupon || !Coupon::isUsable($coupon)) {
                    throw new \RuntimeException('该优惠券已停用或不在活动时间内');
                }
                if (!Coupon::isApplicableToProduct($coupon, (int)$p['id'])) {
                    throw new \RuntimeException('该优惠券不适用于当前商品');
                }

                $couponDiscount = Coupon::calcDiscount($coupon, $total);
                if (bccomp($couponDiscount, '0', Coupon::SCALE) <= 0) {
                    throw new \RuntimeException(
                        '当前订单金额未达到该券的使用门槛（' . Coupon::describe($coupon) . '）'
                    );
                }

                $couponId    = (int)$coupon['id'];
                $afterCoupon = bcsub($total, $couponDiscount, Coupon::SCALE);
            }

            // ---- 第二步：余额抵扣（后余额，基数为券后应付）----
            $balancePaid = '0.00';
            $payAmount   = $afterCoupon;

            if ($useBalance && Setting::balanceEnabled() && bccomp($afterCoupon, '0', BalanceLog::SCALE) > 0) {
                // 行锁读取实时余额，避免与并发兑换/下单串号
                $rowBal = $db->first('SELECT balance FROM ly_users WHERE id=? FOR UPDATE', [$userId]);
                $avail  = BalanceLog::normalize($rowBal['balance'] ?? '0');
                if (bccomp($avail, '0', BalanceLog::SCALE) > 0) {
                    if (bccomp($avail, $afterCoupon, BalanceLog::SCALE) >= 0) {
                        // 余额充足：全额抵扣，无需在线支付
                        $balancePaid = $afterCoupon;
                        $payAmount   = '0.00';
                    } else {
                        $balancePaid = $avail;
                        $payAmount   = bcsub($afterCoupon, $avail, BalanceLog::SCALE);
                    }
                }
            }

            // 恒等式自检：券 + 余额 + 实付 必须等于订单总额（1.3.0 起新增 coupon_discount）
            if (bccomp(
                bcadd($couponDiscount, bcadd($balancePaid, $payAmount, Order::SCALE), Order::SCALE),
                $total,
                Order::SCALE
            ) !== 0) {
                throw new \RuntimeException('订单金额计算异常，请重试');
            }

            // 纯余额支付：下单即直接扣款发货，不走支付宝
            $pureBalance = $payAmount === '0.00' && bccomp($balancePaid, '0', BalanceLog::SCALE) > 0;
            // 0 元订单（1.7.10）：商品原价 0 元或券后恰好 0 元且无余额抵扣，
            // 免支付直接发货，通道记为 free（与 balance 同走 settle 结算路径但不扣余额）
            $freeOrder = $payAmount === '0.00' && bccomp($balancePaid, '0', BalanceLog::SCALE) === 0;
            $payChannel  = $freeOrder ? 'free' : ($pureBalance ? 'balance' : $channel);

            $id = $db->insert('ly_orders', [
                'order_no'        => $orderNo,
                'user_id'         => $userId,
                'product_id'      => (int)$p['id'],
                'product_name'    => $p['name'],
                'stock_id'        => $stockId,
                'amount'          => $total,
                'coupon_id'       => $couponId,
                'coupon_discount' => $couponDiscount,
                'pay_amount'      => $payAmount,
                'balance_paid'    => $balancePaid,
                'quantity'        => 1,
                'pay_channel'     => $payChannel,
                'status'          => self::STATUS_PENDING,
                'client_ip'       => client_ip(),
            ]);

            if ($stockId > 0) {
                Stock::bindOrder($stockId, $id);
            }

            // 核销券（条件 UPDATE：0未使用 → 1已使用），失败则整体回滚
            if ($couponId > 0 && $userCouponId !== null) {
                if (!UserCoupon::markUsed($userCouponId, $id)) {
                    throw new \RuntimeException('优惠券核销失败，请重新下单');
                }
                $db->query('UPDATE ly_coupons SET used_count = used_count + 1 WHERE id=?', [$couponId]);
                log_write('coupon_used', '订单 ' . $orderNo . ' 使用优惠券 #' . $couponId, [
                    'order_id'        => $id,
                    'user_coupon_id'  => $userCouponId,
                    'coupon_discount' => $couponDiscount,
                ]);
            }

            // 纯余额支付：立即扣减余额（在同一事务内，失败则整体回滚）
            if ($pureBalance) {
                BalanceLog::debit(
                    $userId,
                    $balancePaid,
                    BalanceLog::TYPE_CONSUME,
                    $id,
                    '余额支付订单 ' . $orderNo
                );
                self::settleBalanceOrder($db, $id, $orderNo, $balancePaid);
            } elseif ($freeOrder) {
                // 0 元订单：无需扣款，直接结算发货（流水号前缀 FREE 区分于余额单）
                self::settleBalanceOrder($db, $id, $orderNo, '0.00', 'FREE-');
            }

            return $id;
        });

        // 事务已提交：此时才发起 MNBT 开通（网络调用不占用订单行锁）
        self::flushAfterCommit();

        return self::find($orderId);
    }

    /**
     * 纯余额 / 0 元免费订单的支付结算（在 createWithStock 事务内调用）
     * 标记已支付并自动发货，与支付宝回调 markPaid() 的落库结果保持一致；
     * $tradePrefix 用于区分流水来源：BAL=余额单、FREE-=0 元免费单
     */
    private static function settleBalanceOrder(Database $db, int $orderId, string $orderNo, string $balancePaid, string $tradePrefix = 'BAL'): void
    {
        $now = date('Y-m-d H:i:s');
        $orderRow = self::find($orderId);
        $product = $orderRow ? Product::find((int)$orderRow['product_id']) : null;
        $db->update('ly_orders', [
            'status'    => self::STATUS_PAID,
            'trade_no'  => $tradePrefix . $orderNo,
            'paid_at'   => $now,
            'expire_at' => Product::expireAt($product, $now),
        ], 'id=?', [$orderId]);

        self::doDeliver($db, $orderId, $now);

        // MNBT 商品：标记为开通中后，需在事务提交后立即发起开通。
        // 这里只登记"待办"，由 createWithStock 在事务结束后统一执行，
        // 避免在持有订单行锁期间做网络调用。
        if (Product::isMnbt($product)) {
            self::$afterCommit[] = $orderId;
        }
    }

    /**
     * 事务提交后待执行的 MNBT 开通订单队列
     * 在 createWithStock / markPaid 事务结束后统一 drain
     */
    private static array $afterCommit = [];

    /** 执行并清空待开通队列 */
    private static function flushAfterCommit(): void
    {
        if (!self::$afterCommit) {
            return;
        }
        $queue = self::$afterCommit;
        self::$afterCommit = [];
        foreach (array_unique($queue) as $oid) {
            try {
                self::reopenMnbtDelivery((int)$oid);
            } catch (\Throwable $e) {
                // 开通失败已在 reopenMnbtDelivery 内落库为待重试/失败态，
                // 这里兜底记录，绝不让异常冒泡影响支付主流程
                log_write('mnbt_deliver_exception', 'MNBT 开通异常 order#' . $oid . '：' . $e->getMessage(), [
                    'order_id' => (int)$oid,
                ]);
            }
        }
    }

    /**
     * 自动发货的公共逻辑（调用方需保证已持有订单事务 / 行锁）
     */
    private static function doDeliver(Database $db, int $orderId, string $now): void
    {
        $order = $db->first('SELECT * FROM ly_orders WHERE id=?', [$orderId]);
        if (!$order) {
            return;
        }
        $product = Product::find((int)$order['product_id']);

        // ---------- MNBT 实时开通分支 ----------
        //
        // 关键：这里**不在事务内**发起 HTTP 调用。
        // 开通接口最长可能耗 15 秒，若在事务里等待，会长时间持有 orders 行锁
        // 并拖慢支付回调（支付宝约 25s 即超时重试）。
        // 因此本分支只做「标记开通中 + 返回」，真正开通由 reopenMnbtDelivery()
        // 在事务提交后执行（见 markPaid / settleBalanceOrder 的调用点）。
        if (Product::isMnbt($product)) {
            $db->update('ly_orders', [
                'deliver_status' => self::DELIVER_PENDING,
                'deliver_error'  => '',
            ], 'id=?', [$orderId]);
            return;
        }

        $autoDeliver = $product ? (int)$product['auto_deliver'] === 1 : true;
        $hasPanel = (int)$order['stock_id'] > 0;

        if ($autoDeliver && $hasPanel) {
            $db->update('ly_orders', [
                'status'       => self::STATUS_DELIVERED,
                'delivered_at' => $now,
            ], 'id=?', [$orderId]);
        } elseif ($autoDeliver && !$hasPanel && $product && (int)$product['stock_mode'] === 1) {
            // 下单时未占库存（异常情况），尝试补发
            $stock = Stock::allocate((int)$order['product_id']);
            if ($stock) {
                Stock::bindOrder((int)$stock['id'], $orderId);
                $db->update('ly_orders', [
                    'stock_id'     => (int)$stock['id'],
                    'status'       => self::STATUS_DELIVERED,
                    'delivered_at' => $now,
                ], 'id=?', [$orderId]);
            }
        }

        Product::increaseSales((int)$order['product_id']);
    }

    // ==================================================================
    //  MNBT 实时开通
    // ==================================================================

    /** 自动开通状态 */
    public const DELIVER_NONE    = 0;  // 无需自动开通 / 未开始
    public const DELIVER_PENDING = 1;  // 开通中（标记后待执行）
    public const DELIVER_DONE    = 2;  // 已开通
    public const DELIVER_RETRY   = 3;  // 待重试
    public const DELIVER_FAILED  = 4;  // 开通失败（已/待退款）

    public const DELIVER_STATUS_TEXT = [
        self::DELIVER_NONE    => '—',
        self::DELIVER_PENDING => '开通中',
        self::DELIVER_DONE    => '已开通',
        self::DELIVER_RETRY   => '待重试',
        self::DELIVER_FAILED  => '开通失败',
    ];

    /**
     * 执行 MNBT 实时开通（事务外调用）
     *
     * 流程：
     *   1. 幂等守卫：仅处理 deliver_status ∈ {PENDING, RETRY} 的订单
     *   2. 生成全局唯一主机账号（前缀 + 订单号后缀），落库占位防并发撞号
     *   3. 调用 MNBT gn=kt 开通主机
     *   4. 成功 → 在 ly_stocks 写一条 source=2 的「开通记录」并绑定订单
     *      （复用现有面板展示链路，前台/后台订单详情无需另写查询）
     *      注意：此时不走 Stock::allocate（那条路径面向预录库存），
     *            而是直接 insert 一条已售记录，避免与库存池语义混淆。
     *   5. 失败 → 按重试策略标记 RETRY 或终态 FAILED 并退款
     *
     * @param bool $manual 是否由后台人工触发。人工触发时不受「最大重试次数」限制
     *                      （管理员已确认上游恢复），失败后回落到待重试态而非直接退款，
     *                      以免在人工排障过程中误退用户款项。
     * @return bool 本次是否开通成功
     */
    public static function reopenMnbtDelivery(int $orderId, bool $manual = false): bool
    {
        $db = Database::instance();

        // ---- 1. 幂等守卫（短事务，只做状态读取与占位）----
        $ctx = $db->transaction(function () use ($db, $orderId, $manual) {
            $order = $db->first('SELECT * FROM ly_orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$order) {
                return null;
            }
            if ((int)$order['deliver_status'] === self::DELIVER_DONE) {
                return null; // 已开通，绝不重复开（避免重复扣供应商额度）
            }
            $allowStatus = [self::DELIVER_PENDING, self::DELIVER_RETRY];
            // 人工重试额外放行「开通失败」态：用于排障后重新拉起（此时订单可能已被退款）
            if ($manual) {
                $allowStatus[] = self::DELIVER_FAILED;
            }
            if (!in_array((int)$order['deliver_status'], $allowStatus, true)) {
                return null;
            }
            $product = Product::find((int)$order['product_id']);
            if (!Product::isMnbt($product)) {
                return null;
            }

            $tries = (int)$order['deliver_tries'] + 1;
            $username = (string)$order['mnbt_username'];
            if ($username === '') {
                $username = self::makeMnbtUsername($product, (string)$order['order_no']);
            }

            // 落库尝试次数与账号（账号先落库，重试时沿用同一个，保证幂等）
            $db->update('ly_orders', [
                'deliver_status' => self::DELIVER_PENDING,
                'deliver_tries'  => $tries,
                'mnbt_username'  => $username,
            ], 'id=?', [$orderId]);

            return [
                'order'    => $order,
                'product'  => $product,
                'username' => $username,
                'tries'    => $tries,
            ];
        });

        if ($ctx === null) {
            return false;
        }

        $order    = $ctx['order'];
        $product  = $ctx['product'];
        $username = $ctx['username'];
        $tries    = $ctx['tries'];

        // ---- 2. 事务外调用 MNBT 接口 ----
        $password = self::makeMnbtPassword();
        $error    = '';
        $ok       = false;

        try {
            $client = new \App\Mnbt\MnbtClient();
            if (!$client->ready()) {
                throw new \RuntimeException('MNBT 配置不完整：' . implode('、', $client->missingFields()));
            }
            $client->createHost([
                'username' => $username,
                'password' => $password,
                'webdx'    => (int)($product['mnbt_webdx'] ?? 0),
                'sqldx'    => (int)($product['mnbt_sqldx'] ?? 0),
                'sizemax'  => (int)($product['mnbt_sizemax'] ?? 0),
                'type'     => (int)($product['mnbt_type'] ?? 2),
                'ymbds'    => (int)($product['mnbt_ymbds'] ?? 0),
                'dqtime'   => self::mnbtDueDate($order),
            ]);
            $ok = true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // ---- 3. 结果落库 ----
        if ($ok) {
            self::markMnbtDelivered($db, $orderId, $username, $password, $product);
            log_write('mnbt_deliver_ok', 'MNBT 主机开通成功 ' . $order['order_no'], [
                'order_id' => $orderId,
                'username' => $username,
                'tries'    => $tries,
            ]);
            return true;
        }

        // 失败：判断是否还有重试机会
        $maxRetry   = Setting::mnbtMaxRetry();          // 额外重试次数
        $maxTries   = $maxRetry + 1;                    // 含首次
        $canRetry   = $tries < $maxTries;

        // 人工触发时永不自动退款：改为回落到待重试态，交由管理员判断，
        // 避免「排障期间上游抖动」导致用户被静默退款、订单被关。
        if ($manual && !$canRetry) {
            $canRetry = true;
        }

        if ($canRetry) {
            $db->update('ly_orders', [
                'deliver_status' => self::DELIVER_RETRY,
                'deliver_error'  => mb_substr($error, 0, 250),
            ], 'id=?', [$orderId]);
            log_write('mnbt_deliver_retry', 'MNBT 开通失败，已排入重试 ' . $order['order_no'], [
                'order_id' => $orderId,
                'tries'    => $tries,
                'max'      => $maxTries,
                'error'    => $error,
            ]);
            return false;
        }

        // 重试耗尽 → 退款到余额，避免吞掉买家的钱
        self::refundMnbtFailure($db, $orderId, $order, $error);
        return false;
    }

    /** 开通成功：写面板记录 + 标记订单已发货 */
    private static function markMnbtDelivered(
        Database $db,
        int $orderId,
        string $username,
        string $password,
        ?array $product
    ): void {
        $now = date('Y-m-d H:i:s');
        $panelUrl = '';
        try {
            $client = new \App\Mnbt\MnbtClient();
            $panelUrl = $client->panelHomeUrl();
        } catch (\Throwable $e) {
            // 展示地址取不到不影响发货
        }
        if ($panelUrl === '') {
            $panelUrl = (string)Setting::get('mnbt_api_url', '');
        }

        $db->transaction(function () use ($db, $orderId, $username, $password, $product, $panelUrl, $now) {
            // 再次确认未开通，防并发重复写
            $cur = $db->first('SELECT * FROM ly_orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$cur || (int)$cur['deliver_status'] === self::DELIVER_DONE) {
                return;
            }

            $stockId = (int)$cur['stock_id'];
            if ($stockId <= 0) {
                $stockId = $db->insert('ly_stocks', [
                    'product_id'  => (int)$cur['product_id'],
                    'panel_url'   => $panelUrl,
                    'panel_user'  => $username,
                    'panel_pass'  => $password,
                    'remark'      => 'MNBT 实时开通' . ($cur['expire_at'] ? ' · 到期 ' . $cur['expire_at'] : ''),
                    'source'      => 2,
                    'mn_username' => $username,
                    'status'      => 1,
                    'order_id'    => $orderId,
                    'sold_at'     => $now,
                ]);
            } else {
                $db->update('ly_stocks', [
                    'panel_url'   => $panelUrl,
                    'panel_user'  => $username,
                    'panel_pass'  => $password,
                    'source'      => 2,
                    'mn_username' => $username,
                    'status'      => 1,
                    'order_id'    => $orderId,
                    'sold_at'     => $now,
                ], 'id=?', [$stockId]);
            }

            $db->update('ly_orders', [
                'stock_id'       => $stockId,
                'mnbt_username'  => $username,
                'status'         => self::STATUS_DELIVERED,
                'delivered_at'   => $now,
                'deliver_status' => self::DELIVER_DONE,
                'deliver_error'  => '',
            ], 'id=?', [$orderId]);

            Product::increaseSales((int)$cur['product_id']);
        });
    }

    /**
     * 开通彻底失败：按买家实付金额退款到余额，订单标记已退款
     *
     * 退款口径：pay_amount（网关实付部分）。余额抵扣与优惠券的退还在
     * 「订单关闭」路径已由 closeWithRefund 负责；本方法只处理已支付订单，
     * 因此需一并把余额抵扣部分退回——这里合并为「订单总额 - 券减免」，
     * 即 balance_paid + pay_amount，等价于用户实际付出的钱。
     */
    private static function refundMnbtFailure(Database $db, int $orderId, array $order, string $error): void
    {
        $refund = BalanceLog::normalize(
            bcadd(
                BalanceLog::normalize($order['balance_paid'] ?? '0'),
                BalanceLog::normalize($order['pay_amount'] ?? '0'),
                BalanceLog::SCALE
            )
        );

        $db->transaction(function () use ($db, $orderId, $order, $refund, $error) {
            $cur = $db->first('SELECT * FROM ly_orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$cur || (int)$cur['deliver_status'] === self::DELIVER_DONE) {
                return;
            }
            if ((int)$cur['deliver_status'] === self::DELIVER_FAILED) {
                return; // 已处理过，幂等
            }

            if (bccomp($refund, '0', BalanceLog::SCALE) > 0) {
                BalanceLog::credit(
                    (int)$cur['user_id'],
                    $refund,
                    BalanceLog::TYPE_REFUND,
                    $orderId,
                    'MNBT 开通失败退款 订单 ' . $cur['order_no']
                );
            }

            $db->update('ly_orders', [
                'status'         => self::STATUS_REFUNDED,
                'deliver_status' => self::DELIVER_FAILED,
                'deliver_error'  => mb_substr($error, 0, 250),
            ], 'id=?', [$orderId]);
        });

        log_write('mnbt_deliver_failed_refund', 'MNBT 开通多次失败，已退款至余额 ' . $order['order_no'], [
            'order_id' => $orderId,
            'refund'   => $refund,
            'error'    => $error,
        ]);
    }

    /**
     * 生成全局唯一 MNBT 主机账号
     *
     * 规则：商品前缀（或系统默认前缀）+ 订单号后缀
     * 订单号本身已含时间与随机段（random_order_no），全局唯一，
     * 这里再做一次存在性校验兜底，冲突时追加随机字符重试。
     */
    public static function makeMnbtUsername(?array $product, string $orderNo): string
    {
        $prefix = trim((string)($product['mnbt_prefix'] ?? ''));
        if ($prefix === '') {
            $prefix = (string)Setting::get('mnbt_default_prefix', 'ly');
        }
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix);
        $prefix = ltrim((string)$prefix, '0123456789_');
        if ($prefix === '') {
            $prefix = 'ly';
        }
        $prefix = mb_substr($prefix, 0, 12);

        // 订单号形如 20261002143012xxxxx，取字母数字段作为后缀
        $suffix = preg_replace('/[^A-Za-z0-9]/', '', $orderNo);
        if ($suffix === '') {
            $suffix = (string)time();
        }
        $username = $prefix . $suffix;

        // 兜底去重：与本地已开通记录比对（MNBT 无查重接口）
        $db = Database::instance();
        $base = $username;
        for ($i = 0; $i < 5; $i++) {
            $exists = (int)$db->value(
                'SELECT COUNT(*) FROM ly_orders WHERE mnbt_username=?',
                [$username]
            );
            if ($exists === 0) {
                break;
            }
            $username = $base . substr(bin2hex(random_bytes(2)), 0, 3);
        }

        return mb_substr($username, 0, 110);
    }

    /** 生成主机密码（12 位强随机，去除易混淆字符） */
    public static function makeMnbtPassword(int $len = 12): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max   = strlen($chars) - 1;
        $out   = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * MNBT 开通时使用的到期时间（Y-m-d）
     *
     * 优先用订单的 expire_at（支付时按商品有效期算好的），
     * 无有效期（永久）时返回 '0'，与 MNBT 接口约定一致。
     */
    public static function mnbtDueDate(array $order): string
    {
        $expire = (string)($order['expire_at'] ?? '');
        if ($expire === '' || $expire === '0000-00-00 00:00:00') {
            return '0';
        }
        $ts = strtotime($expire);
        return $ts !== false ? date('Y-m-d', $ts) : '0';
    }

    /**
     * 扫描并重试「待重试」的 MNBT 订单
     *
     * 由计划任务（cron/tick）与页面惰性触发调用。
     * 每轮限量执行，避免单个请求耗时过长。
     *
     * @return array{retried:int,ok:int,failed:int}
     */
    public static function retryMnbtDeliver(int $limit = 5): array
    {
        if (!Setting::mnbtConfigured()) {
            return ['retried' => 0, 'ok' => 0, 'failed' => 0];
        }

        $db = Database::instance();
        $rows = $db->select(
            'SELECT id FROM ly_orders WHERE deliver_status IN (?, ?) AND mnbt_username<>"" ORDER BY id ASC LIMIT ' . (int)$limit,
            [self::DELIVER_RETRY, self::DELIVER_PENDING]
        );

        $ok = 0;
        $failed = 0;
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            // 正在开通中的订单（PENDING 且 tries 已被本次流程设置）不应被并发重试：
            // 用「超过 2 分钟仍停在 PENDING」作为卡死判定，避免误重试
            if ((int)$db->value('SELECT deliver_status FROM ly_orders WHERE id=?', [$id]) === self::DELIVER_PENDING) {
                $stuck = $db->first(
                    'SELECT TIMESTAMPDIFF(SECOND, paid_at, NOW()) AS sec FROM ly_orders WHERE id=?',
                    [$id]
                );
                // PENDING 状态由 reopenMnbtDelivery 同步处理，正常情况下不会长期停留；
                // 若停留超过 2 分钟说明进程中断（如 PHP 超时），可安全重试
                if ($stuck && (int)$stuck['sec'] < 120) {
                    continue;
                }
            }
            if (self::reopenMnbtDelivery($id)) {
                $ok++;
            } elseif ((int)$db->value('SELECT deliver_status FROM ly_orders WHERE id=?', [$id]) === self::DELIVER_FAILED) {
                $failed++;
            }
        }

        return ['retried' => count($rows), 'ok' => $ok, 'failed' => $failed];
    }

    /** 待重试 / 开通失败订单数（后台看板用） */
    public static function mnbtPendingCount(): array
    {
        $db = Database::instance();
        return [
            'retry'  => (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE deliver_status=?', [self::DELIVER_RETRY]),
            'failed' => (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE deliver_status=?', [self::DELIVER_FAILED]),
            'done'   => (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE deliver_status=?', [self::DELIVER_DONE]),
        ];
    }

    /**
     * 捐赠支付：买家声明已完成付款（1.7.9）
     *
     * 仅对待支付中的捐赠订单生效；幂等（重复声明保持已声明状态）。
     * 订单状态不变，等待管理员在后台核实到账后调用 markPaid 自动发货。
     */
    public static function claimDonate(int $orderId, int $userId): bool
    {
        if ($orderId <= 0 || $userId <= 0) {
            return false;
        }
        return Database::instance()->update(
            'ly_orders',
            ['user_claimed' => 1, 'claim_at' => date('Y-m-d H:i:s')],
            'id=? AND user_id=? AND pay_channel=? AND status=?',
            [$orderId, $userId, 'donate', self::STATUS_PENDING]
        ) > 0;
    }


    /**
     * 标记订单已支付并自动发货（幂等）
     *
     * 若订单含余额抵扣（balance_paid > 0），在此刻真正扣减用户余额；
     * 纯余额订单已在 createWithStock 内扣款，其 pay_channel='balance'，此处不重复扣。
     *
     * @return bool 是否本次完成支付处理
     */
    public static function markPaid(int $orderId, string $tradeNo, string $rawExtra = ''): bool
    {
        $db = Database::instance();
        $result = $db->transaction(function () use ($db, $orderId, $tradeNo) {
            // 行锁保证幂等
            $order = $db->first('SELECT * FROM ly_orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$order) {
                return false;
            }
            if ((int)$order['status'] === self::STATUS_CLOSED && (string)$order['trade_no'] === '') {
                // 订单因超时未支付被自动关闭，但支付成功回调此刻到达（用户在超时边界完成了支付）。
                // 不能吞掉用户的钱：恢复订单并发货；资源已不可得则全额退款到余额。
                // 说明：优惠券在关单时已退回用户账户，此处不再重复核销（记日志备案）。
                $recoverProduct = Product::find((int)$order['product_id']);

                if (Product::isMnbt($recoverProduct)) {
                    // MNBT 商品不占本站库存池，交由提交后的开通队列重新开通
                    $db->update('ly_orders', [
                        'status'         => self::STATUS_PAID,
                        'trade_no'       => $tradeNo,
                        'paid_at'        => date('Y-m-d H:i:s'),
                        'expire_at'      => Product::expireAt($recoverProduct, date('Y-m-d H:i:s')),
                        'deliver_status' => self::DELIVER_PENDING,
                    ], 'id=?', [$orderId]);
                    self::$afterCommit[] = $orderId;
                    log_write('mnbt_order_recovered', '超时关闭的 MNBT 订单收到支付回调，已重新排入开通队列 ' . $order['order_no'], [
                        'order_id' => $orderId,
                        'trade_no' => $tradeNo,
                    ]);
                    return true;
                }

                $stock = Stock::allocate((int)$order['product_id']); // 注意：返回完整行数组，非 id
                if ($stock) {
                    $stockId = (int)$stock['id'];
                    Stock::bindOrder($stockId, $orderId);
                    $db->update('ly_orders', ['stock_id' => $stockId], 'id=?', [$orderId]);
                    $order['stock_id'] = $stockId;
                    log_write('order_recovered', '超时关闭订单收到支付回调，已恢复并发货 ' . $order['order_no'], [
                        'order_id' => $orderId,
                        'trade_no' => $tradeNo,
                    ]);
                } else {
                    // 库存已被其他订单抢完：按用户实付金额（网关到账部分）退款到余额，标记已退款
                    $refund = BalanceLog::normalize($order['pay_amount']);
                    BalanceLog::credit(
                        (int)$order['user_id'],
                        $refund,
                        BalanceLog::TYPE_REFUND,
                        $orderId,
                        '超时订单 ' . $order['order_no'] . ' 支付到达时库存不足，退款至余额'
                    );
                    $db->update('ly_orders', [
                        'status'   => self::STATUS_REFUNDED,
                        'trade_no' => $tradeNo,
                        'paid_at'  => date('Y-m-d H:i:s'),
                    ], 'id=?', [$orderId]);
                    log_write('order_refunded_no_stock', '超时关闭订单支付到达且无库存，已退款至余额 ' . $order['order_no'], [
                        'order_id' => $orderId,
                        'trade_no' => $tradeNo,
                        'refund'   => $refund,
                    ]);
                    return true;
                }
            } elseif ((int)$order['status'] !== self::STATUS_PENDING) {
                // 已处理过，直接返回，避免重复发货
                return false;
            }

            // 部分余额抵扣的订单：在线支付成功后，真正扣减用户余额
            $balancePaid = BalanceLog::normalize($order['balance_paid'] ?? '0');
            if (bccomp($balancePaid, '0', BalanceLog::SCALE) > 0
                && (string)$order['pay_channel'] !== 'balance'
            ) {
                if (!BalanceLog::debit(
                    (int)$order['user_id'],
                    $balancePaid,
                    BalanceLog::TYPE_CONSUME,
                    $orderId,
                    '订单余额抵扣 ' . $order['order_no']
                )) {
                    // 余额已被并发消耗，拒绝发货，由回调返回 fail 让支付宝重试
                    throw new \RuntimeException('余额不足，无法完成抵扣');
                }
                log_write('balance_consume', '订单支付成功扣减余额 ' . $order['order_no'], [
                    'order_id' => $orderId,
                    'amount'   => $balancePaid,
                ]);
            }

            $now = date('Y-m-d H:i:s');
            $product = Product::find((int)$order['product_id']);
            $db->update('ly_orders', [
                'status'    => self::STATUS_PAID,
                'trade_no'  => $tradeNo,
                'paid_at'   => $now,
                'expire_at' => Product::expireAt($product, $now),
            ], 'id=?', [$orderId]);

            // 自动发货（与纯余额支付共用同一套发货逻辑）
            self::doDeliver($db, $orderId, $now);

            // MNBT 商品：登记到「事务提交后执行」队列，避免在持锁期间做网络调用
            if (Product::isMnbt($product)) {
                self::$afterCommit[] = $orderId;
            }
            return true;
        });

        // 事务已提交：发起 MNBT 实时开通
        self::flushAfterCommit();

        return (bool)$result;
    }

    /**
     * 关闭待支付订单：退还已抵扣的余额，并退回已核销的优惠券
     * 条件：订单仍为待支付
     */
    public static function closeWithRefund(int $orderId): bool
    {
        $db = Database::instance();
        return (bool)$db->transaction(function () use ($db, $orderId) {
            $order = $db->first('SELECT * FROM ly_orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$order || (int)$order['status'] !== self::STATUS_PENDING) {
                return false;
            }
            $balancePaid = BalanceLog::normalize($order['balance_paid'] ?? '0');

            if ((int)$order['stock_id'] > 0) {
                Stock::release((int)$order['stock_id']);
            }
            $db->update('ly_orders', ['status' => self::STATUS_CLOSED, 'stock_id' => 0], 'id=?', [$orderId]);

            // 退还优惠券（1已使用 → 0未使用），券可再次使用
            if ((int)($order['coupon_id'] ?? 0) > 0) {
                if (UserCoupon::revertByOrder($orderId)) {
                    log_write('coupon_refund', '订单关闭退回优惠券 ' . $order['order_no'], [
                        'order_id'  => $orderId,
                        'coupon_id' => (int)$order['coupon_id'],
                    ]);
                }
            }

            // 退还已抵扣的余额
            //
            // 关键：余额只在下述两种时机被真正扣减（二者互斥）
            //   1) 纯余额单：createWithStock 时即扣（pay_channel === 'balance'）
            //   2) 部分抵扣单：支付宝支付成功、markPaid 时才扣（pay_channel === 'qr'/'page'）
            //
            // 本方法只处理「仍为待支付」的订单，此时部分抵扣单尚未扣过余额，
            // 因此只对纯余额单退款；否则会对未扣款的订单凭空加钱。
            if (bccomp($balancePaid, '0', BalanceLog::SCALE) > 0
                && (string)$order['pay_channel'] === 'balance'
            ) {
                BalanceLog::credit(
                    (int)$order['user_id'],
                    $balancePaid,
                    BalanceLog::TYPE_REFUND,
                    $orderId,
                    '订单关闭退还余额 ' . $order['order_no']
                );
                log_write('balance_refund', '订单关闭退还余额 ' . $order['order_no'], [
                    'order_id' => $orderId,
                    'amount'   => $balancePaid,
                ]);
            }
            return true;
        });
    }

    /** 手动发货（管理员指定库存行 / 直接填写） */
    public static function manualDeliver(int $orderId, int $stockId): bool
    {
        $db = Database::instance();
        return (bool)$db->transaction(function () use ($db, $orderId, $stockId) {
            $order = $db->first('SELECT * FROM ly_orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$order) {
                return false;
            }
            $stock = $db->first('SELECT * FROM ly_stocks WHERE id=? FOR UPDATE', [$stockId]);
            if (!$stock) {
                return false;
            }
            if ((int)$stock['status'] === 1 && (int)$stock['order_id'] !== $orderId) {
                return false;
            }
            $db->update('ly_stocks', [
                'status'   => 1,
                'order_id' => $orderId,
                'sold_at'  => date('Y-m-d H:i:s'),
            ], 'id=?', [$stockId]);

            $now = date('Y-m-d H:i:s');
            $db->update('ly_orders', [
                'stock_id'     => $stockId,
                'status'       => self::STATUS_DELIVERED,
                'paid_at'      => $order['paid_at'] ?: $now,
                'delivered_at' => $now,
            ], 'id=?', [$orderId]);
            return true;
        });
    }

    /** 关闭订单并释放库存 */
    public static function close(int $orderId): bool
    {
        $db = Database::instance();
        return (bool)$db->transaction(function () use ($db, $orderId) {
            $order = $db->first('SELECT * FROM ly_orders WHERE id=? FOR UPDATE', [$orderId]);
            if (!$order || (int)$order['status'] !== self::STATUS_PENDING) {
                return false;
            }
            if ((int)$order['stock_id'] > 0) {
                Stock::release((int)$order['stock_id']);
            }
            $db->update('ly_orders', ['status' => self::STATUS_CLOSED, 'stock_id' => 0], 'id=?', [$orderId]);
            return true;
        });
    }

    /** 用户订单列表 */
    public static function forUser(int $userId, int $page = 1, int $perPage = 10): array
    {
        $db = Database::instance();
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE user_id=?', [$userId]);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT * FROM ly_orders WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            [$userId]
        );
        return ['total' => $total, 'rows' => $rows];
    }

    /** 后台订单分页 */
    public static function paginate(int $page, int $perPage, array $filter = []): array
    {
        $db = Database::instance();
        $where = '1=1';
        $params = [];
        if (!empty($filter['status']) || (isset($filter['status']) && $filter['status'] === '0')) {
            if ($filter['status'] !== '' && $filter['status'] !== null) {
                $where .= ' AND o.status=?';
                $params[] = (int)$filter['status'];
            }
        }
        if (!empty($filter['keyword'])) {
            $where .= ' AND (o.order_no LIKE ? OR u.email LIKE ? OR o.trade_no LIKE ?)';
            $kw = '%' . $filter['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        $total = (int)$db->value(
            'SELECT COUNT(*) FROM ly_orders o LEFT JOIN ly_users u ON u.id=o.user_id WHERE ' . $where,
            $params
        );
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT o.*, u.email AS user_email, u.nickname AS user_nickname
             FROM ly_orders o LEFT JOIN ly_users u ON u.id=o.user_id
             WHERE ' . $where . ' ORDER BY o.id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            $params
        );
        return ['total' => $total, 'rows' => $rows];
    }

    /** 取订单的面板信息 */
    public static function panel(int $orderId): ?array
    {
        return Database::instance()->first(
            'SELECT s.* FROM ly_stocks s INNER JOIN ly_orders o ON o.stock_id = s.id WHERE o.id=? LIMIT 1',
            [$orderId]
        ) ?: null;
    }

    /**
     * 判断该订单是否为 MNBT 实时开通订单
     *
     * 依据：关联商品当前为 MNBT 交付方式。为兼容商品事后被改成其他交付方式
     * 的历史订单，额外用「已写入 mnbt_username」作为兜底判据。
     */
    public static function isMnbtOrder(array $order): bool
    {
        if ((string)($order['mnbt_username'] ?? '') !== '') {
            return true;
        }
        if ((int)($order['deliver_status'] ?? 0) > 0) {
            return true;
        }
        $product = Product::find((int)($order['product_id'] ?? 0));
        return $product ? Product::isMnbt($product) : false;
    }

    /**
     * MNBT 一键登录中转地址（不含密钥，可安全渲染进页面）
     *
     * 之所以要中转：MNBT 的一键登录直链形如
     *   {站点}/user/idcdl.php?gn=logine&username=x&password=y
     * 明文密码若直接渲染进 HTML，会残留在浏览器历史、Referer、页面源码与截图里。
     * 因此前台只输出本中转地址，由服务端在点击瞬间跳转到真实直链。
     */
    public static function mnbtLoginRelay(int $orderId): string
    {
        return base_url('index.php?r=order/mnbtlogin&id=' . $orderId);
    }

    /**
     * 是否存在允许执行 MNBT 一键登录的订单
     *
     * 前置校验（三者同时满足才放行，缺一不可）：
     *   ① 订单存在且归属校验通过（调用方负责传入已鉴权的 user_id）
     *   ② 订单已成功开通（deliver_status = DONE 且订单已发货）
     *   ③ MNBT 接口配置完整（能拼出站点地址）
     */
    public static function mnbtLoginTarget(int $orderId, int $userId, bool $asAdmin = false): ?array
    {
        $order = Database::instance()->first('SELECT * FROM ly_orders WHERE id=? LIMIT 1', [$orderId]);
        if (!$order) {
            return null;
        }
        // 归属校验：前台必须本人订单；后台需管理员（由控制器已鉴权）
        if (!$asAdmin && (int)$order['user_id'] !== $userId) {
            return null;
        }
        // 必须已开通成功，避免「开通中/待重试/已退款」的订单被拿去登录
        if ((int)$order['deliver_status'] !== self::DELIVER_DONE || (int)$order['status'] < self::STATUS_PAID) {
            return null;
        }
        $username = (string)($order['mnbt_username'] ?? '');
        if ($username === '') {
            return null;
        }

        $panel = self::panel($orderId);
        if (!$panel || (string)$panel['panel_pass'] === '') {
            return null;
        }

        $client = \App\Mnbt\MnbtClient::fromSetting();
        if ($client === null) {
            return null;
        }

        return [
            'url'      => $client->loginUrl($username, (string)$panel['panel_pass']),
            'username' => $username,
        ];
    }

    /**
     * 统计需要人工关注的 MNBT 开通异常订单数（后台 Dashboard 用）
     *
     * @return array{retry:int,failed:int,total:int}
     */
    public static function mnbtAttentionCount(): array
    {
        $db  = Database::instance();
        $one = static function (string $sql) use ($db): int {
            $row = $db->first($sql);
            return (int)($row['n'] ?? 0);
        };
        $retry  = $one('SELECT COUNT(*) AS n FROM ly_orders WHERE deliver_status=' . self::DELIVER_RETRY);
        $failed = $one('SELECT COUNT(*) AS n FROM ly_orders WHERE deliver_status=' . self::DELIVER_FAILED);
        return ['retry' => $retry, 'failed' => $failed, 'total' => $retry + $failed];
    }

    /**
     * 后台手动触发一次 MNBT 开通重试
     *
     * 仅允许 deliver_status ∈ {RETRY, PENDING, FAILED} 的订单重试：
     *   - RETRY   ：常规重试路径
     *   - PENDING ：上次进程中断留下的悬挂态，允许重新拉起
     *   - FAILED  ：已退款的订单，人工确认可重开（会先冲正退款？—— 不做，改为仅打印提示，
     *               避免自动化改动用户资金；如需重开请走人工发新单）
     */
    public static function adminRetryMnbt(int $orderId): array
    {
        $order = self::find($orderId);
        if (!$order) {
            return ['ok' => false, 'msg' => '订单不存在'];
        }
        $status = (int)$order['deliver_status'];
        if ($status === self::DELIVER_DONE) {
            return ['ok' => false, 'msg' => '该订单已成功开通，无需重试'];
        }
        if ($status === self::DELIVER_FAILED) {
            return ['ok' => false, 'msg' => '该订单已退款关闭，请引导用户重新下单（避免重复扣款）'];
        }
        if (!in_array($status, [self::DELIVER_RETRY, self::DELIVER_PENDING], true)) {
            return ['ok' => false, 'msg' => '该订单当前状态不支持开通重试'];
        }
        if ((int)$order['status'] !== self::STATUS_PAID) {
            return ['ok' => false, 'msg' => '仅「已支付」订单可重试开通'];
        }

        // 手动重试不受 max_retry 限制（人工已确认上游已恢复），但仍累加计数便于审计
        $ok = self::reopenMnbtDelivery($orderId, true);
        $after = self::find($orderId);
        return [
            'ok'      => $ok,
            'msg'     => $ok ? '开通成功，主机已交付' : ('开通仍未成功：' . (string)($after['deliver_error'] ?? '未知原因')),
            'status'  => (int)($after['deliver_status'] ?? 0),
        ];
    }

    /**
     * 超时未支付订单自动关闭
     *
     * 使用 closeWithRefund（而非 close）：
     * 释放库存的同时必须退还用户已核销的优惠券与纯余额抵扣款，否则关单等于吞掉用户资产。
     * 每单独立事务 + FOR UPDATE 幂等，与支付回调并发安全。
     *
     * @return int 本次实际关闭的订单数
     */
    public static function autoCloseExpired(int $minutes): int
    {
        if ($minutes <= 0) {
            return 0; // 0 = 关闭自动关单
        }
        $db   = Database::instance();
        $n    = 0;
        $seen = [];
        // 分批处理：单次最多 200 单，防止积压订单拖长请求
        while ($n < 200) {
            $rows = $db->select(
                'SELECT id FROM ly_orders WHERE status=0 AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE) LIMIT 50',
                [$minutes]
            );
            if (!$rows) {
                break;
            }
            $progress = false;
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                if (isset($seen[$id])) {
                    continue; // 防御：异常数据导致死循环
                }
                $seen[$id] = true;
                if (self::closeWithRefund($id)) {
                    $n++;
                }
                $progress = true;
            }
            if (!$progress) {
                break;
            }
        }
        if ($n > 0) {
            log_write('order_auto_closed', "自动关闭超时未支付订单 {$n} 笔（超时 {$minutes} 分钟）");
        }
        return $n;
    }

    /** 统计 */
    public static function stats(): array
    {
        $db = Database::instance();
        $today = date('Y-m-d 00:00:00');
        return [
            'order_total'   => (int)$db->value('SELECT COUNT(*) FROM ly_orders'),
            'order_today'   => (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE created_at>=?', [$today]),
            'paid_total'    => (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE status IN (1,2)'),
            'pending'       => (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE status=0'),
            'income_total'  => (float)$db->value('SELECT IFNULL(SUM(amount),0) FROM ly_orders WHERE status IN (1,2)'),
            'income_today'  => (float)$db->value('SELECT IFNULL(SUM(amount),0) FROM ly_orders WHERE status IN (1,2) AND paid_at>=?', [$today]),
            'user_total'    => (int)$db->value('SELECT COUNT(*) FROM ly_users'),
            'product_total' => (int)$db->value('SELECT COUNT(*) FROM ly_products'),
            'stock_avail'   => (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE status=0'),
            'stock_sold'    => (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE status=1'),
        ];
    }

    /** 最近 7 天销售额 */
    public static function incomeTrend(int $days = 7): array
    {
        $db = Database::instance();
        $rows = $db->select(
            'SELECT DATE(paid_at) AS d, IFNULL(SUM(amount),0) AS amount, COUNT(*) AS cnt
             FROM ly_orders
             WHERE status IN (1,2) AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(paid_at) ORDER BY d ASC',
            [$days - 1]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' day'));
            $out[] = [
                'date'   => $d,
                'label'  => date('m-d', strtotime($d)),
                'amount' => isset($map[$d]) ? (float)$map[$d]['amount'] : 0.0,
                'count'  => isset($map[$d]) ? (int)$map[$d]['cnt'] : 0,
            ];
        }
        return $out;
    }

    // ==================== 到期管理（1.7.0） ====================

    /** 到期统计：即将到期（7 天内）与已到期待删机（未标记删机）的数量 */
    public static function expireStats(): array
    {
        $db = Database::instance();
        return [
            'due'     => (int)$db->value(
                'SELECT COUNT(*) FROM ly_orders WHERE status IN (1,2)
                 AND expire_at IS NOT NULL AND disposed_at IS NULL
                 AND expire_at > NOW() AND expire_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)'
            ),
            'expired' => (int)$db->value(
                'SELECT COUNT(*) FROM ly_orders WHERE status IN (1,2)
                 AND expire_at IS NOT NULL AND disposed_at IS NULL AND expire_at <= NOW()'
            ),
        ];
    }

    /**
     * 到期管理列表
     * @param string $kind due=7 天内到期 | expired=已到期待删机 | disposed=已标记删机
     */
    public static function expireList(string $kind, int $limit = 100): array
    {
        $base = 'FROM ly_orders o
                 LEFT JOIN ly_stocks s ON s.id = o.stock_id
                 LEFT JOIN ly_users u ON u.id = o.user_id
                 WHERE o.status IN (1,2) AND o.expire_at IS NOT NULL';
        if ($kind === 'due') {
            $where = ' AND o.disposed_at IS NULL AND o.expire_at > NOW() AND o.expire_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)';
            $order = 'ORDER BY o.expire_at ASC';
        } elseif ($kind === 'expired') {
            $where = ' AND o.disposed_at IS NULL AND o.expire_at <= NOW()';
            $order = 'ORDER BY o.expire_at ASC';
        } else {
            $where = ' AND o.disposed_at IS NOT NULL';
            $order = 'ORDER BY o.disposed_at DESC';
        }
        $rows = Database::instance()->select(
            'SELECT o.id, o.order_no, o.product_name, o.paid_at, o.expire_at, o.reminded_at, o.disposed_at,
                    u.email AS user_email, s.panel_url, s.panel_user
             ' . $base . $where . ' ' . $order . ' LIMIT ' . (int)$limit
        );
        foreach ($rows as &$r) {
            $r['days_left'] = $r['expire_at']
                ? (int)floor((strtotime($r['expire_at']) - time()) / 86400)
                : null;
        }
        return $rows;
    }

    /** 管理员标记已删机 */
    public static function markDisposed(int $orderId): bool
    {
        return Database::instance()->update(
            'ly_orders',
            ['disposed_at' => date('Y-m-d H:i:s')],
            'id=? AND status IN (1,2) AND disposed_at IS NULL',
            [$orderId]
        ) > 0;
    }

    /**
     * 到期邮件提醒扫描：到期前 7 天内、尚未提醒过的已支付订单，给买家发提醒邮件。
     * 每轮最多 20 封；邮件服务不可用时立即停止（下轮再试），绝不抛异常。
     *
     * @return int 本轮成功发送的提醒数
     */
    public static function remindDue(int $days = 7): int
    {
        $db = Database::instance();
        // 快速短路：无待提醒行直接返回（走 idx_expire 索引，高频页面调用零负担）
        $exists = (int)$db->value(
            'SELECT COUNT(*) FROM ly_orders
             WHERE status IN (1,2) AND expire_at IS NOT NULL AND reminded_at IS NULL
               AND expire_at > NOW() AND expire_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
             LIMIT 1',
            [$days]
        );
        if ($exists === 0) {
            return 0;
        }

        $rows = $db->select(
            'SELECT o.*, u.email AS user_email
             FROM ly_orders o JOIN ly_users u ON u.id = o.user_id
             WHERE o.status IN (1,2) AND o.expire_at IS NOT NULL AND o.reminded_at IS NULL
               AND o.expire_at > NOW() AND o.expire_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
             ORDER BY o.expire_at ASC
             LIMIT 20',
            [$days]
        );

        $sent = 0;
        foreach ($rows as $o) {
            $ok = \App\Mail\MailService::sendExpireReminder((string)$o['user_email'], $o);
            if (!$ok) {
                // 邮件服务不可用/发送失败：停止本轮，保留 reminded_at=NULL，下轮自动重试
                break;
            }
            $db->update('ly_orders', ['reminded_at' => date('Y-m-d H:i:s')], 'id=?', [$o['id']]);
            $sent++;
        }
        if ($sent > 0) {
            log_write('expire_reminder_sent', '到期提醒邮件已发送 ' . $sent . ' 封');
        }
        return $sent;
    }

    /** 到期展示文本：用户订单页用（「剩余 5 天」「已到期 3 天」「永久有效」） */
    public static function expireView(?string $expireAt): string
    {
        if ($expireAt === null || $expireAt === '') {
            return '永久有效';
        }
        $diff = (int)floor((strtotime($expireAt) - time()) / 86400);
        if ($diff > 0) {
            return '剩余 ' . $diff . ' 天（' . date('Y-m-d', strtotime($expireAt)) . ' 到期）';
        }
        if ($diff === 0) {
            return '今天到期（' . date('Y-m-d', strtotime($expireAt)) . '）';
        }
        return '已到期 ' . (-$diff) . ' 天';
    }
}
