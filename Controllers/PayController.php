<?php

namespace App\Controllers;

use App\Mail\MailService;
use App\Models\Order;
use App\Models\Setting;
use App\Payment\AlipayGateway;
use App\Payment\YiPayGateway;

/**
 * 支付宝回调：异步通知 / 同步返回 / 演示支付
 */
class PayController extends BaseController
{
    /**
     * 异步通知（支付宝服务器 -> 本站）
     * 必须输出纯文本 success 才算处理成功，否则支付宝会重试
     */
    public function notify(): void
    {
        $raw = file_get_contents('php://input');
        $post = $_POST;
        if (empty($post) && $raw !== '') {
            parse_str($raw, $post);
        }

        log_write('alipay_notify', '收到异步通知：' . ($post['out_trade_no'] ?? 'unknown'), [
            'trade_status' => $post['trade_status'] ?? '',
            'trade_no'     => $post['trade_no'] ?? '',
        ]);

        if (empty($post)) {
            echo 'fail: empty body';
            return;
        }

        try {
            $gw = new AlipayGateway();
            $result = $gw->verifyNotify($post);
        } catch (\Throwable $e) {
            log_write('alipay_notify_error', $e->getMessage());
            echo 'fail: ' . $e->getMessage();
            return;
        }

        if (!$result['ok']) {
            log_write('alipay_notify_reject', $result['msg'], ['out_trade_no' => $post['out_trade_no'] ?? '']);
            echo 'fail: ' . $result['msg'];
            return;
        }

        $order = Order::findByNo($result['out_trade_no']);
        if (!$order) {
            log_write('alipay_notify_orphan', '订单不存在：' . $result['out_trade_no']);
            echo 'success'; // 返回 success 避免无意义重试
            return;
        }

        // 金额校验：基准为 pay_amount（订单总额 - 余额抵扣），而非 amount
        if (bccomp(money($order['pay_amount']), money($result['amount']), 2) !== 0) {
            log_write('alipay_notify_amount_mismatch', '金额不一致', [
                'order_no' => $order['order_no'],
                'expect'   => money($order['pay_amount']),
                'actual'   => money($result['amount']),
                'amount'   => money($order['amount']),
                'balance'  => money($order['balance_paid']),
            ]);
            echo 'fail: amount mismatch';
            return;
        }

        // 幂等发货
        $done = Order::markPaid((int)$order['id'], $result['trade_no'], json_encode($post, JSON_UNESCAPED_UNICODE));
        log_write('alipay_notify_ok', ($done ? '发货完成：' : '重复通知已忽略：') . $order['order_no'], [
            'trade_no' => $result['trade_no'],
        ]);

        // 先应答支付宝（要求 5 秒内返回 success），再异步发发货通知邮件
        echo 'success';
        $this->finishRequest();

        if ($done) {
            MailService::sendDelivery((int)$order['id']);
        }
    }

    /**
     * 提前结束 HTTP 请求，让后续耗时操作（如 SMTP 发信）不阻塞客户端
     *
     * PHP-FPM 环境下 fastcgi_finish_request() 会把已输出的内容发送并关闭连接；
     * 非 FPM 环境（如 php -S 内置服务器、CLI）退化为同步执行，不影响正确性。
     */
    private function finishRequest(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    /**
     * 同步返回（用户支付完成后浏览器跳回）
     */
    public function returnUrl(): void
    {
        $orderNo = (string)input('out_trade_no');
        if ($orderNo === '') {
            flash_set('warning', '未获取到订单信息');
            redirect(url('order/list'));
        }

        $order = Order::findByNo($orderNo);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('order/list'));
        }

        // 兜底：同步返回时主动查一次（异步通知可能延迟）
        if ((int)$order['status'] === Order::STATUS_PENDING && Setting::alipayConfigured()) {
            try {
                $gw = new AlipayGateway();
                $r = $gw->query($orderNo);
                $st = $r['trade_status'] ?? '';
                if (in_array($st, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                    if (Order::markPaid((int)$order['id'], $r['trade_no'] ?? '', json_encode($r, JSON_UNESCAPED_UNICODE))) {
                        MailService::sendDelivery((int)$order['id']);
                    }
                }
            } catch (\Throwable $e) {
                log_write('alipay_return_query_error', $e->getMessage());
            }
        }

        $order = Order::find((int)$order['id']);
        if ((int)$order['status'] >= Order::STATUS_PAID) {
            flash_set('success', '支付成功！面板登录信息已自动分配，请查收。');
        } elseif (input('trade_no') !== '' || input('out_trade_no') !== '') {
            flash_set('warning', '收款确认中，请稍候刷新页面查看发货结果。');
        }
        redirect(url('order/detail', ['id' => (int)$order['id']]));
    }

    /**
     * 演示支付（沙箱/无外网回调时使用）
     * 仅在后台开启"演示支付"开关后可用，用于完整演示下单→发货链路
     */
    public function demoPay(): void
    {
        if (!Setting::demoPayEnabled()) {
            flash_set('danger', '演示支付功能已关闭');
            redirect(url('home/index'));
        }

        $orderNo = (string)input('out_trade_no');
        $order = Order::findByNo($orderNo);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('order/list'));
        }

        if ((int)$order['status'] !== Order::STATUS_PENDING) {
            redirect(url('order/detail', ['id' => (int)$order['id']]));
        }

        // 纯余额订单在下单事务内已直接结算，不应出现在待支付状态
        if ((string)$order['pay_channel'] === 'balance') {
            flash_set('warning', '该订单已通过余额支付完成');
            redirect(url('order/detail', ['id' => (int)$order['id']]));
        }

        $tradeNo = 'DEMO' . date('YmdHis') . random_int(1000, 9999);
        $done = Order::markPaid((int)$order['id'], $tradeNo, '{"demo":true}');
        log_write('demo_pay', '演示支付完成：' . $order['order_no'], ['trade_no' => $tradeNo]);

        if ($done) {
            MailService::sendDelivery((int)$order['id']);
        }

        flash_set('success', '【演示模式】已模拟支付成功，系统已自动发货！');
        redirect(url('order/detail', ['id' => (int)$order['id']]));
    }

    // ==================================================================
    //  易支付回调
    // ==================================================================

    /**
     * 易支付异步通知（易支付服务器 -> 本站，标准为 GET 携带签名参数）
     * 必须输出纯文本 success 才算处理成功，否则易支付会重试
     */
    public function yiNotify(): void
    {
        // 标准易支付以 GET 通知；兼容 POST / raw body
        $params = $_GET;
        if (empty($params)) {
            $raw = file_get_contents('php://input');
            if ($raw !== '') {
                parse_str($raw, $params);
            }
        }

        log_write('yipay_notify', '收到易支付通知：' . ($params['out_trade_no'] ?? 'unknown'), [
            'trade_status' => $params['trade_status'] ?? '',
            'trade_no'     => $params['trade_no'] ?? '',
            'type'         => $params['type'] ?? '',
        ]);

        if (empty($params)) {
            echo 'fail';
            return;
        }

        try {
            $gw = new YiPayGateway();
            $result = $gw->verifyNotify($params);
        } catch (\Throwable $e) {
            log_write('yipay_notify_error', $e->getMessage());
            echo 'fail';
            return;
        }

        if (!$result['ok']) {
            log_write('yipay_notify_reject', $result['msg'], ['out_trade_no' => $params['out_trade_no'] ?? '']);
            echo 'fail';
            return;
        }

        $order = Order::findByNo($result['out_trade_no']);
        if (!$order) {
            log_write('yipay_notify_orphan', '订单不存在：' . $result['out_trade_no']);
            echo 'success'; // 返回 success 避免无意义重试
            return;
        }

        // 通道校验：只处理易支付通道订单，防止其他通道订单被易支付回调串单
        if (!YiPayGateway::isChannel((string)$order['pay_channel'])) {
            log_write('yipay_notify_channel_mismatch', '订单通道与回调来源不符：' . $order['order_no'], [
                'pay_channel' => (string)$order['pay_channel'],
            ]);
            echo 'fail';
            return;
        }

        // 金额校验：基准为 pay_amount（订单总额 - 券 - 余额抵扣），而非 amount
        if (bccomp(money($order['pay_amount']), money($result['amount']), 2) !== 0) {
            log_write('yipay_notify_amount_mismatch', '金额不一致', [
                'order_no' => $order['order_no'],
                'expect'   => money($order['pay_amount']),
                'actual'   => money($result['amount']),
                'amount'   => money($order['amount']),
                'balance'  => money($order['balance_paid']),
            ]);
            echo 'fail: amount mismatch';
            return;
        }

        // 幂等发货（含部分余额抵扣单在此刻真正扣减余额）
        $done = Order::markPaid((int)$order['id'], $result['trade_no'], json_encode($params, JSON_UNESCAPED_UNICODE));
        log_write('yipay_notify_ok', ($done ? '发货完成：' : '重复通知已忽略：') . $order['order_no'], [
            'trade_no' => $result['trade_no'],
        ]);

        // 先应答易支付，再异步发发货通知邮件
        echo 'success';
        $this->finishRequest();

        if ($done) {
            MailService::sendDelivery((int)$order['id']);
        }
    }

    /**
     * 易支付同步返回（用户支付完成后浏览器跳回）
     */
    public function yiReturn(): void
    {
        $orderNo = (string)input('out_trade_no');
        if ($orderNo === '') {
            flash_set('warning', '未获取到订单信息');
            redirect(url('order/list'));
        }

        $order = Order::findByNo($orderNo);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('order/list'));
        }

        // 兜底：同步返回时主动查一次单（异步通知可能延迟 / 丢失）
        if ((int)$order['status'] === Order::STATUS_PENDING
            && YiPayGateway::isChannel((string)$order['pay_channel'])
            && Setting::yipayEnabled()) {
            try {
                $gw = new YiPayGateway();
                $r = $gw->queryOrder($orderNo);
                if (YiPayGateway::querySaysPaid($r)
                    && bccomp(money($order['pay_amount']), money((string)($r['money'] ?? '0')), 2) === 0) {
                    if (Order::markPaid((int)$order['id'], (string)($r['trade_no'] ?? ''), json_encode($r, JSON_UNESCAPED_UNICODE))) {
                        MailService::sendDelivery((int)$order['id']);
                    }
                }
            } catch (\Throwable $e) {
                log_write('yipay_return_query_error', $e->getMessage());
            }
        }

        $order = Order::find((int)$order['id']);
        if ((int)$order['status'] >= Order::STATUS_PAID) {
            flash_set('success', '支付成功！面板登录信息已自动分配，请查收。');
        } else {
            flash_set('warning', '收款确认中，请稍候刷新页面查看发货结果。');
        }
        redirect(url('order/detail', ['id' => (int)$order['id']]));
    }
}
