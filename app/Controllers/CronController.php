<?php

namespace App\Controllers;

use App\Models\Order;
use App\Models\Setting;

/**
 * 计划任务入口（供宝塔等外部 cron 每分钟调用）
 *
 * URL: {站点}/index.php?r=cron/tick&key={cron_key}
 * cron_key 首次调用自动生成，后台「系统设置」中可查看完整 URL。
 *
 * 职责：清理超时未支付订单 + 到期邮件提醒扫描
 *（页面访问时的惰性触发在无人时段会失效，这里兜底）。
 */
class CronController extends BaseController
{
    public function tick(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // cron_key 惰性生成：首次访问落库，之后固定不变
        $stored = (string)Setting::get('cron_key');
        if ($stored === '') {
            $stored = bin2hex(random_bytes(16));
            Setting::set('cron_key', $stored);
        }

        $key = (string)input('key');
        if ($key === '' || !hash_equals($stored, $key)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => '密钥错误，请使用后台提供的完整任务 URL'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $minutes = (int)Setting::get('order_expire_min', '5');
        $closed  = $minutes > 0 ? Order::autoCloseExpired($minutes) : 0;

        // 到期邮件提醒（到期前 7 天，邮件服务不可用时返回 0 下轮重试）
        $reminded = 0;
        try {
            $reminded = Order::remindDue(7);
        } catch (\Throwable $e) {
            // 计划任务绝不 500
        }

        // MNBT 开通失败重试（含重试耗尽后的自动退款兜底）
        $mnbt = ['retried' => 0, 'ok' => 0, 'failed' => 0];
        try {
            $mnbt = Order::retryMnbtDeliver(5);
        } catch (\Throwable $e) {
            // 同上，重试异常不得影响计划任务整体返回
        }

        echo json_encode([
            'ok'       => true,
            'closed'   => $closed,
            'minutes'  => $minutes,
            'reminded' => $reminded,
            'mnbt'     => $mnbt,
        ], JSON_UNESCAPED_UNICODE);
    }
}
