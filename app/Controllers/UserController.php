<?php

namespace App\Controllers;

use App\Auth;
use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\RedeemCode;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCoupon;

/**
 * 前台用户中心：余额、兑换码与优惠券
 */
class UserController extends BaseController
{
    /** 兑换码提交限流：次数 / 窗口秒数 */
    private const REDEEM_MAX    = 10;
    private const REDEEM_WINDOW = 3600;

    /** 领券限流：次数 / 窗口秒数 */
    private const CLAIM_MAX    = 20;
    private const CLAIM_WINDOW = 3600;

    /** 余额明细每页条数 */
    private const LOG_PER_PAGE = 15;

    /** 我的券包每页条数 */
    private const COUPON_PER_PAGE = 12;

    /** 领券中心每页条数 */
    private const COUPON_CLAIM_PER_PAGE = 12;

    /**
     * 我的余额页
     */
    public function balance(): void
    {
        Auth::requireUser();
        $user = Auth::user();
        $userId = (int)$user['id'];

        $page = max(1, (int)input('page', 1));

        // 直接查库取实时余额，避免依赖可能过期的会话缓存
        $balance  = User::balanceOf($userId);
        $logPage  = BalanceLog::forUser($userId, $page, self::LOG_PER_PAGE);
        $stats    = BalanceLog::statsForUser($userId);

        $this->view('user/balance', [
            'balance'      => $balance,
            'logs'         => $logPage['rows'],
            'logTotal'     => $logPage['total'],
            'page'         => $page,
            'perPage'      => self::LOG_PER_PAGE,
            'balanceStats' => $stats,
            'redeemEnabled' => Setting::redeemEnabled(),
            'minRedeem'    => Setting::redeemMinAmount(),
            'maxRedeem'    => Setting::redeemMaxAmount(),
            'title'        => '我的余额 - ' . Setting::get('site_name'),
        ]);
    }

    /**
     * 兑换码兑换接口（AJAX，返回 JSON）
     *
     * 安全：登录 + CSRF + 会话级限流（10 次/小时）
     * 格式非法不消耗限流次数（在 RedeemCode 内先做格式校验）
     */
    public function redeem(): void
    {
        Auth::requireUser();
        $this->requirePost();

        if (!Setting::redeemEnabled()) {
            $this->jsonError('兑换功能已关闭，请联系管理员');
        }

        $userId  = Auth::userId();
        $rawCode = (string)input('code', '');

        // 先做格式校验：明显无效的输入直接拒绝，且不消耗限流额度
        if ($rawCode === '') {
            $this->jsonError('请输入兑换码');
        }
        if (!RedeemCode::isValidFormat($rawCode)) {
            $this->jsonError('兑换码格式不正确，请检查后重新输入');
        }

        $throttleKey = 'redeem:' . $userId;
        if (Auth::tooManyAttempts($throttleKey, self::REDEEM_MAX, self::REDEEM_WINDOW)) {
            log_write('redeem_throttled', '兑换码提交过于频繁', ['user_id' => $userId]);
            $this->jsonError('提交过于频繁，请稍后再试', 429);
        }
        Auth::hitAttempt($throttleKey);

        $result = RedeemCode::redeem($rawCode, $userId);

        if (!$result['ok']) {
            log_write('redeem_fail', '兑换失败：' . $result['msg'], ['user_id' => $userId]);
            $this->jsonError($result['msg']);
        }

        // 余额已变动，清除缓存保证后续读取是新值
        Auth::flushUserCache();

        log_write('redeem_ok', '兑换成功 ' . $result['amount'], [
            'user_id'  => $userId,
            'code_id'  => $result['code_id'],
            'amount'   => $result['amount'],
        ]);

        // 成功后清空限流计数，避免正常用户被误伤
        Auth::clearAttempt($throttleKey);

        $this->jsonOk([
            'msg'     => $result['msg'],
            'amount'  => money($result['amount']),
            'balance' => money($result['after']),
        ]);
    }

    // ==================== 优惠券 ====================

    /** 领券中心 + 我的券包 */
    public function coupons(): void
    {
        Auth::requireUser();
        $userId = Auth::userId();
        $page   = max(1, (int)input('page', 1));
        $status = input('status');

        $mine = UserCoupon::forUser($userId, null, $page, self::COUPON_PER_PAGE);

        $claimCenter = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        if (Setting::couponClaimEnabled()) {
            $claimCenter = UserCoupon::claimable($userId, max(1, (int)input('cpage', 1)), self::COUPON_CLAIM_PER_PAGE);
        }

        $this->view('user/coupons', [
            'rows'          => $mine['rows'],
            'total'         => $mine['total'],
            'page'          => $page,
            'perPage'       => self::COUPON_PER_PAGE,
            'status'        => $status,
            'claimCenter'   => $claimCenter,
            'couponEnabled' => Setting::couponEnabled(),
            'claimEnabled'  => Setting::couponClaimEnabled(),
            'stats'         => UserCoupon::statsForUser($userId),
            'title'         => '我的优惠券 - ' . Setting::get('site_name'),
        ]);
    }

    /**
     * 自主领取优惠券（AJAX）
     *
     * 与会话级限流配合，防止刷券；真正的一致性由 UserCoupon::claim 的事务 + 行锁保证。
     */
    public function claimCoupon(): void
    {
        Auth::requireUser();
        $this->requirePost();

        if (!Setting::couponClaimEnabled()) {
            $this->jsonError('领券中心当前已关闭');
        }

        $userId   = Auth::userId();
        $couponId = (int)input('coupon_id', 0);
        if ($couponId <= 0) {
            $this->jsonError('请选择要领取的优惠券');
        }

        $throttleKey = 'coupon_claim:' . $userId;
        if (Auth::tooManyAttempts($throttleKey, self::CLAIM_MAX, self::CLAIM_WINDOW)) {
            log_write('coupon_claim_throttled', '领券过于频繁', ['user_id' => $userId]);
            $this->jsonError('领取过于频繁，请稍后再试', 429);
        }
        Auth::hitAttempt($throttleKey);

        $result = UserCoupon::claim($couponId, $userId);
        if (!$result['ok']) {
            $this->jsonError($result['msg']);
        }

        Auth::clearAttempt($throttleKey);

        $holds = UserCoupon::countForUser($couponId, $userId);
        $this->jsonOk([
            'msg'  => $result['msg'],
            'mine' => $holds,
        ]);
    }

    /**
     * 结算试算（AJAX）：某商品 + 金额下，当前用户可用的券及各自减免额
     *
     * 供商品详情页动态渲染用券下拉框；提交时服务端仍会重新校验，不信任前端结果。
     */
    public function couponQuote(): void
    {
        Auth::requireUser();
        $this->requirePost();

        if (!Setting::couponEnabled()) {
            $this->jsonOk(['list' => []]);
        }

        $userId    = Auth::userId();
        $productId = (int)input('product_id', 0);
        $amount    = BalanceLog::normalize((string)input('amount', '0'));

        $product = Product::find($productId);
        if (!$product || (int)$product['status'] !== 1) {
            $this->jsonError('商品不存在或已下架', 404);
        }
        if (bccomp($amount, '0', BalanceLog::SCALE) <= 0) {
            $this->jsonOk(['list' => []]);
        }

        // 以服务端商品价为准，避免前端传任意金额试探
        $amount = BalanceLog::normalize($product['price']);

        $list = UserCoupon::usableForProduct($userId, $productId, $amount);

        $this->jsonOk([
            'list' => array_map(static function (array $it): array {
                return [
                    'user_coupon_id' => (int)$it['user_coupon_id'],
                    'name'           => $it['name'],
                    'discount'       => money($it['discount']),
                    'describe'       => $it['describe'],
                    'expires_at'     => $it['expires_at'],
                ];
            }, $list),
        ]);
    }
}
