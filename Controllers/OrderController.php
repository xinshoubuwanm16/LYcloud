<?php

namespace App\Controllers;

use App\Auth;
use App\Mail\MailService;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Payment\AlipayGateway;
use App\Payment\YiPayGateway;

/**
 * 订单：下单 / 支付 / 查看
 */
class OrderController extends BaseController
{
    /**
     * 当前可用的在线支付通道白名单
     * 官方支付宝（qr/page）+ 已启用的易支付子通道（yipay_alipay / ...）
     * 全部未配置时回落官方两模式（支付页会给出未配置提示，演示模式仍可用）
     *
     * @return string[]
     */
    private function allowedChannels(): array
    {
        $allowed = [];
        // 捐赠支付（1.7.9）：就绪时排在首位，下单页与回落逻辑均默认选中
        if (Setting::donateReady()) {
            $allowed[] = 'donate';
        }
        if (Setting::alipayConfigured()) {
            $allowed = array_merge($allowed, ['qr', 'page']);
        }
        if (Setting::yipayEnabled()) {
            foreach (Setting::yipayChannels() as $t) {
                $allowed[] = YiPayGateway::makeChannel($t);
            }
        }
        return $allowed ?: ['qr', 'page'];
    }

    /** 下单 */
    public function create(): void
    {
        Auth::requireUser();
        $this->requirePost();

        $productId = (int)input('product_id');
        $channel   = input('pay_channel', 'qr');
        $allowed   = $this->allowedChannels();
        if (!in_array($channel, $allowed, true)) {
            $channel = $allowed[0];
        }
        $useBalance = input('use_balance', '0') === '1';
        $userCouponId = (int)input('user_coupon_id', 0);

        $product = Product::find($productId);
        if (!$product || (int)$product['status'] !== 1) {
            flash_set('danger', '商品不存在或已下架');
            redirect(url('home/index'));
        }

        $user = Auth::user();

        try {
            $order = Order::createWithStock($user, $product, $channel, $useBalance, $userCouponId ?: null);
        } catch (\RuntimeException $e) {
            flash_set('danger', $e->getMessage());
            redirect(url('product/show', ['id' => $productId]));
        }

        log_write('order_create', '创建订单 ' . $order['order_no'], [
            'order_id'        => $order['id'],
            'amount'          => $order['amount'],
            'coupon_discount' => $order['coupon_discount'],
            'pay_amount'      => $order['pay_amount'],
            'balance_paid'    => $order['balance_paid'],
        ]);

        // 纯余额 / 0 元免费单：下单已完成结算，直接进详情页看面板，并异步发发货通知
        if (in_array((string)$order['pay_channel'], ['balance', 'free'], true)) {
            flash_set('success', (string)$order['pay_channel'] === 'free'
                ? '免费领取成功！面板登录信息已自动分配，请查收。'
                : '余额支付成功！面板登录信息已自动分配，请查收。');
            MailService::sendDelivery((int)$order['id']);
            redirect(url('order/detail', ['id' => $order['id']]));
        }

        redirect(url('order/pay', ['id' => $order['id']]));
    }

    /** 支付页：发起支付宝下单 */
    public function pay(): void
    {
        order_auto_close(); // 用户回到支付页时清理超时订单
        Auth::requireUser();
        $id = (int)input('id');
        $user = Auth::user();
        $order = Order::findForUser($id, (int)$user['id']);

        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('order/list'));
        }

        if ((int)$order['status'] !== Order::STATUS_PENDING) {
            redirect(url('order/detail', ['id' => $id]));
        }

        // 纯余额 / 0 元免费订单不应停留在待支付状态；若出现，按已结算处理
        if (in_array((string)$order['pay_channel'], ['balance', 'free'], true)) {
            redirect(url('order/detail', ['id' => $id]));
        }

        $payAmount = money($order['pay_amount']);
        if (bccomp($payAmount, '0.01', 2) < 0) {
            flash_set('warning', '该订单无需在线支付');
            redirect(url('order/detail', ['id' => $id]));
        }

        $allowed = $this->allowedChannels();
        $channel = $order['pay_channel'] ?: 'qr';
        if (!in_array($channel, $allowed, true)) {
            // 订单通道已不可用（如后台停用易支付），回落到首个可用通道
            $channel = $allowed[0];
            \App\Database::instance()->update('ly_orders', ['pay_channel' => $channel], 'id=?', [$id]);
        }
        if (input('channel') !== '') {
            $c = input('channel');
            if (in_array($c, $allowed, true) && $c !== $channel) {
                $channel = $c;
                \App\Database::instance()->update('ly_orders', ['pay_channel' => $channel], 'id=?', [$id]);
            }
        }

        // ---------- 捐赠支付通道（1.7.9）：无网关回调，展示收款码 + 人工核验 ----------
        if ($channel === 'donate') {
            $this->view('home/pay', [
                'order'          => $order,
                'channel'        => 'donate',
                'qrCode'         => '',
                'payJump'        => '',
                'error'          => '',
                'configured'     => true,
                'alipayReady'    => Setting::alipayConfigured(),
                'yipayChannels'  => Setting::yipayEnabled() ? Setting::yipayChannels() : [],
                'demoEnabled'    => Setting::demoPayEnabled(),
                'donateQrAli'    => Setting::donateQr('ali'),
                'donateQrWechat' => Setting::donateQr('wechat'),
                'donateDesc'     => Setting::donateDesc(),
                'title'          => '订单支付 - ' . Setting::get('site_name'),
            ]);
            return;
        }

        // ---------- 易支付通道 ----------
        if (YiPayGateway::isChannel($channel)) {
            $yipayType = YiPayGateway::typeOfChannel($channel);
            if (!Setting::yipayEnabled() || !in_array($yipayType, Setting::yipayChannels(), true)) {
                $this->view('home/pay', [
                    'order'   => $order,
                    'channel' => $channel,
                    'qrCode'  => '',
                    'payJump' => '',
                    'error'   => '易支付通道未启用或未配置，请联系管理员。',
                    'configured' => false,
                    'alipayReady' => Setting::alipayConfigured(),
                    'yipayChannels' => [],
                    'demoEnabled' => Setting::demoPayEnabled(),
                    'title'   => '订单支付 - ' . Setting::get('site_name'),
                ]);
                return;
            }

            $notifyUrl = Setting::get('yipay_notify_url') ?: base_url('index.php?r=pay/yinotify');
            $returnUrl = Setting::get('yipay_return_url') ?: base_url('index.php?r=pay/yireturn');
            $error  = '';
            $qrCode = '';
            $payJump = '';

            try {
                $gw = new YiPayGateway();
                if (Setting::get('yipay_mode', 'jump') === 'api') {
                    // API 模式：站内取码展示 + 轮询查单
                    $r = $gw->mapiPay($order['order_no'], $order['product_name'], $payAmount, $yipayType, $notifyUrl, $returnUrl);
                    $qrCode  = (string)($r['qrcode'] ?? '');
                    $payJump = (string)($r['payurl'] ?? '');
                } else {
                    // 跳转模式：直接跳易支付收银台
                    redirect($gw->submitUrl($order['order_no'], $order['product_name'], $payAmount, $yipayType, $notifyUrl, $returnUrl));
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                log_write('yipay_error', '易支付发起支付失败：' . $e->getMessage(), ['order_no' => $order['order_no']]);
            }

            $this->view('home/pay', [
                'order'   => $order,
                'channel' => $channel,
                'qrCode'  => $qrCode,
                'payJump' => $payJump,
                'error'   => $error,
                'configured' => true,
                'alipayReady' => Setting::alipayConfigured(),
                'yipayChannels' => Setting::yipayChannels(),
                'demoEnabled' => Setting::demoPayEnabled(),
                'title'   => '订单支付 - ' . Setting::get('site_name'),
            ]);
            return;
        }

        // ---------- 官方支付宝通道 ----------
        $notifyUrl = Setting::get('alipay_notify_url') ?: base_url('index.php?r=pay/notify');
        $returnUrl = Setting::get('alipay_return_url') ?: base_url('index.php?r=pay/return');

        $error = '';
        $qrCode = '';
        $configured = Setting::alipayConfigured();

        if ($configured) {
            try {
                $gw = new AlipayGateway();
                if ($channel === 'qr') {
                    $r = $gw->precreate(
                        $order['order_no'],
                        $order['product_name'],
                        $payAmount,
                        $notifyUrl
                    );
                    $qrCode = $r['qr_code'];
                } else {
                    redirect($gw->pagePayUrl(
                        $order['order_no'],
                        $order['product_name'],
                        $payAmount,
                        $notifyUrl,
                        $returnUrl
                    ));
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                log_write('alipay_error', '发起支付失败：' . $e->getMessage(), ['order_no' => $order['order_no']]);
            }
        } else {
            $error = '站点尚未配置支付宝接口，请联系管理员。若为演示环境，可使用下方「模拟支付」完成流程。';
        }

        $this->view('home/pay', [
            'order'      => $order,
            'channel'    => $channel,
            'qrCode'     => $qrCode,
            'payJump'    => '',
            'error'      => $error,
            'configured' => $configured,
            'alipayReady' => $configured,
            'yipayChannels' => Setting::yipayEnabled() ? Setting::yipayChannels() : [],
            'demoEnabled' => Setting::demoPayEnabled(),
            'title'      => '订单支付 - ' . Setting::get('site_name'),
        ]);
    }

    /**
     * 捐赠支付：用户声明已完成付款（1.7.9）
     *
     * 无支付回调，买家扫码付款后主动声明；订单仍保持待支付，
     * 由管理员在后台核实到账后走 markPaid 自动发货。
     */
    public function claimDonate(): void
    {
        Auth::requireUser();
        $this->requirePost();

        $id   = (int)input('id');
        $user = Auth::user();
        $order = Order::findForUser($id, (int)$user['id']);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('order/list'));
        }
        if ((string)$order['pay_channel'] !== 'donate' || (int)$order['status'] !== Order::STATUS_PENDING) {
            flash_set('warning', '该订单无需声明付款');
            redirect(url('order/detail', ['id' => $id]));
        }

        Order::claimDonate($id, (int)$user['id']);
        log_write('donate_claim', '买家声明已完成付款 ' . $order['order_no'], [
            'order_id'  => (int)$order['id'],
            'pay_amount' => $order['pay_amount'],
        ]);
        flash_set('success', '已收到您的付款声明，管理员核实到账后将自动发货，请留意订单状态与邮件通知。');
        redirect(url('order/detail', ['id' => $id]));
    }

    /** 支付状态轮询（当面付扫码用） */
    public function queryStatus(): void
    {
        Auth::requireUser();
        $id = (int)input('id');
        $user = Auth::user();
        $order = Order::findForUser($id, (int)$user['id']);
        if (!$order) {
            $this->jsonError('订单不存在', 404);
        }

        // 本地状态已发货
        if ((int)$order['status'] >= Order::STATUS_PAID) {
            $this->jsonOk(['status' => (int)$order['status'], 'delivered' => true]);
        }

        // 主动向支付网关查询一次（异步通知可能延迟）
        if ($order['trade_no'] === '') {
            if (YiPayGateway::isChannel((string)$order['pay_channel'])) {
                // 易支付通道：查 api.php
                if (Setting::yipayEnabled()) {
                    try {
                        $gw = new YiPayGateway();
                        $r = $gw->queryOrder($order['order_no']);
                        if (YiPayGateway::querySaysPaid($r)) {
                            $apiMoney = (string)($r['money'] ?? '0');
                            if (bccomp(money($order['pay_amount']), money($apiMoney), 2) === 0) {
                                Order::markPaid((int)$order['id'], (string)($r['trade_no'] ?? ''), json_encode($r, JSON_UNESCAPED_UNICODE));
                                $order = Order::find((int)$order['id']);
                                log_write('yipay_query_paid', '轮询发现易支付订单已支付：' . $order['order_no']);
                            } else {
                                log_write('yipay_query_amount_mismatch', '易支付查单金额不一致：' . $order['order_no'], [
                                    'expect' => money($order['pay_amount']),
                                    'actual' => money($apiMoney),
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        // 忽略查询异常（部分平台不支持 api.php），继续返回待支付
                    }
                }
            } elseif (Setting::alipayConfigured()) {
                try {
                    $gw = new AlipayGateway();
                    $r = $gw->query($order['order_no']);
                    $tradeStatus = $r['trade_status'] ?? '';
                    if (in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                        Order::markPaid((int)$order['id'], $r['trade_no'] ?? '', json_encode($r, JSON_UNESCAPED_UNICODE));
                        $order = Order::find((int)$order['id']);
                        log_write('alipay_query_paid', '轮询发现已支付：' . $order['order_no']);
                    }
                } catch (\Throwable $e) {
                    // 忽略查询异常，继续返回待支付
                }
            }
        }

        $order = Order::find((int)$order['id']);
        $this->jsonOk([
            'status'    => (int)$order['status'],
            'delivered' => (int)$order['status'] >= Order::STATUS_DELIVERED,
        ]);
    }

    /** 订单详情（含面板信息） */
    public function detail(): void
    {
        Auth::requireUser();
        $id = (int)input('id');
        $user = Auth::user();
        $order = Order::findForUser($id, (int)$user['id']);

        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('order/list'));
        }

        $panel = null;
        if ((int)$order['status'] >= Order::STATUS_PAID) {
            $panel = Order::panel((int)$order['id']);
        }

        $isMnbt = Order::isMnbtOrder($order);
        $product = Product::find((int)$order['product_id']);

        // 一键登录入口：仅在「已开通成功」时才给出，避免把开通中/失败订单
        // 引到 MNBT 登录页（那里只会提示账号不存在，徒增困惑）
        $mnbtLoginReady = $isMnbt
            && (int)$order['deliver_status'] === Order::DELIVER_DONE
            && $panel !== null
            && Order::mnbtLoginTarget((int)$order['id'], (int)$user['id']) !== null;

        $this->view('order/detail', [
            'order'          => $order,
            'panel'          => $panel,
            'product'        => $product,
            'isMnbt'         => $isMnbt,
            'mnbtLoginReady' => $mnbtLoginReady,
            'deliverText'    => Order::DELIVER_STATUS_TEXT[(int)$order['deliver_status']] ?? '—',
            'title'          => '订单详情 - ' . Setting::get('site_name'),
        ]);
    }

    /** 面板信息直达 */
    public function panel(): void
    {
        Auth::requireUser();
        $id = (int)input('id');
        $user = Auth::user();
        $order = Order::findForUser($id, (int)$user['id']);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('order/list'));
        }
        redirect(url('order/detail', ['id' => $id]) . '#panel');
    }

    /**
     * MNBT 主机面板一键登录（安全中转）
     *
     * 为什么不直接把 MNBT 直链渲染到页面上：
     * MNBT 的一键登录地址形如
     *   {站点}/user/idcdl.php?gn=logine&username=x&password=y
     * 密码是明文 query 参数。若直接写进页面，会残留于：
     *   · 浏览器历史记录 / 前进后退栈
     *   · 页面源码（Ctrl+U 可见）
     *   · 截图、录屏、客服工单
     *   · 第三方脚本与浏览器插件可读的 DOM
     * 因此这里做服务端 302：页面只出现本站地址（不含密码），
     * 密码仅在「点击 → 服务端 → MNBT」这一跳中短暂存在于 Location 头。
     */
    public function mnbtLogin(): void
    {
        Auth::requireUser();
        $id   = (int)input('id');
        $user = Auth::user();

        $target = Order::mnbtLoginTarget($id, (int)$user['id']);
        if ($target === null) {
            flash_set('warning', '该订单暂无可用的主机面板，可能尚在开通中或开通失败。');
            redirect(url('order/detail', ['id' => $id]));
        }

        log_write('mnbt_oneclick_login', '用户一键登录 MNBT 面板', [
            'order_id' => $id,
            'user_id'  => (int)$user['id'],
            'username' => $target['username'],
        ]);

        header('Location: ' . $target['url'], true, 302);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Referrer-Policy: no-referrer');
        exit;
    }

    /** 我的订单 */
    public function listing(): void
    {
        order_auto_close(); // 顺带清理超时未支付订单
        Auth::requireUser();
        $user = Auth::user();
        $page = max(1, (int)input('page', 1));
        $result = Order::forUser((int)$user['id'], $page, 10);

        $this->view('order/list', [
            'orders' => $result['rows'],
            'total'  => $result['total'],
            'page'   => $page,
            'perPage' => 10,
            'title'  => '我的订单 - ' . Setting::get('site_name'),
        ]);
    }
}
