<?php

namespace App\Mail;

use App\Models\Setting;

/**
 * 业务邮件服务：模板 + 降级封装
 *
 * 所有 sendXxx 方法：
 *   - 内部 try/catch，绝不向调用方抛异常
 *   - 返回 bool；失败时可通过 lastError() 取原因（后台"测试发信"用）
 *   - SMTP 未配置时直接返回 false，不做任何网络操作
 *
 * 邮件本身是"锦上添花"，绝不能影响注册、支付等主流程。
 */
class MailService
{
    private static string $lastError = '';

    /** 最近一次发送失败原因 */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** 邮件服务是否可用 */
    public static function configured(): bool
    {
        return Setting::smtpConfigured();
    }

    /** 发送邮件（统一入口，含降级与异常兜底） */
    public static function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        self::$lastError = '';

        if (!self::configured()) {
            self::$lastError = 'SMTP 邮件服务未配置或未启用';
            return false;
        }

        try {
            $mailer = new Mailer();
            $ok = $mailer->send($to, $subject, $html, $text);
            if (!$ok) {
                self::$lastError = $mailer->lastError() ?: '发送失败';
            }
            return $ok;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            if (function_exists('log_write')) {
                log_write('mail_service_error', '发送异常：' . $to . ' - ' . $e->getMessage());
            }
            return false;
        }
    }

    // ==================== 邮件类型 ====================

    /** 注册邮箱验证码 */
    public static function sendVerifyCode(string $to, string $code, int $expireMin = 10): bool
    {
        $site = self::siteName();
        $subject = '【' . $site . '】邮箱验证码';

        $inner = self::card(
            '邮箱验证',
            '<p>您好，您正在注册 <strong>' . self::h($site) . '</strong> 账号。</p>'
            . '<p>您的邮箱验证码是：</p>'
            . '<div style="text-align:center;margin:22px 0;">'
            . '<span style="display:inline-block;font-size:30px;font-weight:700;letter-spacing:7px;'
            . 'color:#1668dc;background:#f0f5ff;border:1px dashed #91caff;border-radius:8px;padding:12px 22px;">'
            . self::h($code) . '</span></div>'
            . '<p style="color:#8c8c8c;font-size:13px;">验证码 ' . (int)$expireMin . ' 分钟内有效，请勿泄露给他人。</p>'
            . '<p style="color:#8c8c8c;font-size:13px;">如果这不是您本人的操作，请忽略本邮件。</p>'
        );

        $text = "【{$site}】邮箱验证码\n\n您的验证码是：{$code}\n"
              . "有效期 {$expireMin} 分钟，请勿泄露给他人。\n\n如果这不是您本人的操作，请忽略本邮件。";

        return self::send($to, $subject, self::shell('邮箱验证码', $inner), $text);
    }

    /**
     * 发货通知（自动从库存行映射面板信息）
     *
     * 这是各支付回调/手动发货统一调用的入口：
     *   - 内部查出订单与库存
     *   - 发送失败只写日志，绝不抛异常，绝不影响主流程
     */
    public static function sendDelivery(int $orderId): bool
    {
        try {
            $order = \App\Models\Order::find($orderId);
            if (!$order) {
                self::$lastError = '订单不存在：' . $orderId;
                return false;
            }

            $email = (string)($order['email'] ?? '');
            if ($email === '') {
                // 兜底：从用户表取邮箱
                $user = \App\Models\User::find((int)$order['user_id']);
                $email = (string)($user['email'] ?? '');
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                self::$lastError = '订单收件邮箱无效';
                return false;
            }

            $panel = [];
            if ((int)($order['stock_id'] ?? 0) > 0) {
                $stock = \App\Models\Order::panel($orderId);
                if ($stock) {
                    $panel = [
                        'url'      => (string)($stock['panel_url'] ?? ''),
                        'username' => (string)($stock['panel_user'] ?? ''),
                        'password' => (string)($stock['panel_pass'] ?? ''),
                        'remark'   => (string)($stock['remark'] ?? ''),
                    ];
                }
            }

            $ok = self::sendDeliveryMail($email, $order, $panel);

            if ($ok) {
                if (function_exists('log_write')) {
                    log_write('mail_delivery_sent', '发货邮件已发送：' . $email, ['order_id' => $orderId]);
                }
            } else {
                if (function_exists('log_write')) {
                    log_write('mail_delivery_failed', '发货邮件发送失败：' . $email, [
                        'order_id' => $orderId,
                        'err'      => self::$lastError,
                    ]);
                }
            }
            return $ok;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            if (function_exists('log_write')) {
                log_write('mail_delivery_error', '发货邮件异常：' . $e->getMessage(), ['order_id' => $orderId]);
            }
            return false;
        }
    }

    /**
     * 发货通知邮件（按已组装好的数据渲染）
     */
    public static function sendDeliveryMail(string $to, array $order, array $panel = []): bool
    {
        $site = self::siteName();
        $orderNo = (string)($order['order_no'] ?? '');
        $product = (string)($order['product_name'] ?? '');
        $amount  = (string)($order['amount'] ?? '0.00');

        $subject = '【' . $site . '】您的面板已开通 - ' . $orderNo;

        $rows = '';
        if (!empty($panel)) {
            $rows .= self::kvRow('面板登录链接', (string)($panel['url'] ?? ''));
            $rows .= self::kvRow('面板账号', (string)($panel['username'] ?? ''));
            $rows .= self::kvRow('面板密码', (string)($panel['password'] ?? ''));
            if (!empty($panel['remark'])) {
                $rows .= self::kvRow('备注', (string)$panel['remark']);
            }
        } else {
            $rows = '<tr><td style="padding:10px;color:#8c8c8c;">面板信息请登录网站订单详情查看。</td></tr>';
        }

        $inner = self::card(
            '开通成功',
            '<p>您好，您的订单已完成支付并<strong style="color:#389e0d;">自动开通</strong>，以下是面板登录信息：</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
            . self::kvRow('订单号', $orderNo)
            . self::kvRow('商品名称', $product)
            . self::kvRow('订单金额', '¥' . $amount)
            . $rows
            . '</table>'
            . '<p style="color:#cf1322;font-size:13px;">首次登录后请立即修改面板初始密码，并妥善保管。</p>'
            . (!empty($panel['url'])
                ? '<p style="text-align:center;margin:20px 0;">'
                  . '<a href="' . self::h((string)$panel['url']) . '" '
                  . 'style="display:inline-block;background:#1668dc;color:#fff;text-decoration:none;'
                  . 'padding:11px 26px;border-radius:6px;font-size:14px;">打开面板</a></p>'
                : '')
        );

        $text = "【{$site}】您的面板已开通\n\n"
              . "订单号：{$orderNo}\n商品：{$product}\n金额：¥{$amount}\n"
              . (!empty($panel)
                  ? "面板链接：" . ($panel['url'] ?? '') . "\n面板账号：" . ($panel['username'] ?? '')
                    . "\n面板密码：" . ($panel['password'] ?? '') . "\n"
                  : '')
              . "\n首次登录后请立即修改初始密码。";

        return self::send($to, $subject, self::shell('面板已开通', $inner), $text);
    }

    /** 找回密码 */
    public static function sendPasswordReset(string $to, string $resetUrl, int $expireMin = 30): bool
    {
        $site = self::siteName();
        $subject = '【' . $site . '】重置密码';

        $inner = self::card(
            '重置密码',
            '<p>您好，我们收到了重置 <strong>' . self::h($site) . '</strong> 账号密码的请求。</p>'
            . '<p style="text-align:center;margin:26px 0;">'
            . '<a href="' . self::h($resetUrl) . '" '
            . 'style="display:inline-block;background:#1668dc;color:#fff;text-decoration:none;'
            . 'padding:12px 32px;border-radius:6px;font-size:15px;">设置新密码</a></p>'
            . '<p style="color:#8c8c8c;font-size:13px;">链接 ' . (int)$expireMin . ' 分钟内有效，且只能使用一次。</p>'
            . '<p style="color:#8c8c8c;font-size:13px;">如果按钮无法点击，请复制以下地址到浏览器打开：</p>'
            . '<p style="word-break:break-all;font-size:12px;color:#8c8c8c;">' . self::h($resetUrl) . '</p>'
            . '<p style="color:#8c8c8c;font-size:13px;">如果这不是您本人的操作，请忽略本邮件，您的密码不会被修改。</p>'
        );

        $text = "【{$site}】重置密码\n\n请在 {$expireMin} 分钟内打开以下链接设置新密码：\n{$resetUrl}\n\n"
              . "链接只能使用一次。如果这不是您本人的操作，请忽略本邮件。";

        return self::send($to, $subject, self::shell('重置密码', $inner), $text);
    }

    /** 到期提醒（到期前 7 天自动发送给买家） */
    public static function sendExpireReminder(string $to, array $order): bool
    {
        $site   = self::siteName();
        $orderNo  = (string)($order['order_no'] ?? '');
        $product  = (string)($order['product_name'] ?? '');
        $expireAt = (string)($order['expire_at'] ?? '');
        $expireDate = $expireAt !== '' ? substr($expireAt, 0, 10) : '';
        $daysLeft = $expireAt !== '' ? max(0, (int)ceil((strtotime($expireAt) - time()) / 86400)) : 0;

        $subject = '【' . $site . '】您的服务将于 ' . $expireDate . ' 到期';

        $inner = self::card(
            '服务到期提醒',
            '<p>您好，您购买的以下服务<strong style="color:#d4380d;">即将到期</strong>：</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
            . self::kvRow('订单号', $orderNo)
            . self::kvRow('商品名称', $product)
            . self::kvRow('到期时间', $expireAt)
            . '</table>'
            . '<p style="text-align:center;margin:18px 0;">'
            . '<span style="display:inline-block;background:#fff1f0;border:1px dashed #ffa39e;color:#cf1322;'
            . 'border-radius:8px;padding:10px 24px;font-size:15px;font-weight:600;">'
            . '剩余 ' . $daysLeft . ' 天</span></p>'
            . '<p style="color:#595959;font-size:13px;">到期后您的面板服务可能被暂停或删除，请及时登录面板<strong>备份重要数据</strong>；'
            . '如需继续使用，请联系客服咨询续费事宜。</p>'
            . '<p style="color:#8c8c8c;font-size:12px;">如已续费或不再使用，请忽略本邮件。</p>'
        );

        $text = "【{$site}】服务到期提醒\n\n"
              . "订单号：{$orderNo}\n商品：{$product}\n到期时间：{$expireAt}（剩余 {$daysLeft} 天）\n\n"
              . "到期后面板服务可能被暂停或删除，请及时备份数据；如需续费请联系客服。";

        return self::send($to, $subject, self::shell('到期提醒', $inner), $text);
    }

    /** 后台测试发信 */
    public static function sendTest(string $to): bool
    {
        $site = self::siteName();
        $subject = '【' . $site . '】SMTP 测试邮件';

        $inner = self::card(
            '测试成功',
            '<p>这是一封来自 <strong>' . self::h($site) . '</strong> 的测试邮件。</p>'
            . '<p>如果您收到这封邮件，说明 SMTP 邮件服务已配置成功，可以正常发送验证码、发货通知与找回密码邮件。</p>'
            . '<p style="color:#8c8c8c;font-size:13px;">发送时间：' . date('Y-m-d H:i:s') . '</p>'
        );

        return self::send($to, $subject, self::shell('SMTP 测试', $inner), '这是一封来自 ' . $site . ' 的 SMTP 测试邮件，收到即表示配置成功。');
    }

    // ==================== 模板辅助 ====================

    private static function siteName(): string
    {
        try {
            $name = (string)Setting::get('site_name', 'LY云计算');
            return $name !== '' ? $name : 'LY云计算';
        } catch (\Throwable $e) {
            return 'LY云计算';
        }
    }

    /** HTML 邮件外壳（兼容 QQ 邮箱等各家的内联样式表友好写法） */
    private static function shell(string $title, string $inner): string
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f5f7fa;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="background:#f5f7fa;padding:28px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="max-width:560px;background:#ffffff;border-radius:10px;'
            . 'box-shadow:0 2px 10px rgba(0,0,0,.06);overflow:hidden;">'
            . $inner
            . '</table>'
            . '<p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#9aa0a6;text-align:center;">'
            . '本邮件由系统自动发送，请勿直接回复。</p>'
            . '</td></tr></table></body></html>';
    }

    private static function card(string $title, string $body): string
    {
        $site = self::siteName();
        return '<tr><td style="background:linear-gradient(135deg,#1668dc,#0958d9);padding:20px 26px;">'
            . '<span style="color:#fff;font-size:17px;font-weight:600;">' . self::h($site) . '</span></td></tr>'
            . '<tr><td style="padding:26px;">'
            . '<h2 style="margin:0 0 16px;font-size:18px;color:#1f2328;">' . self::h($title) . '</h2>'
            . '<div style="font-size:14px;line-height:1.75;color:#3c4043;">' . $body . '</div>'
            . '</td></tr>';
    }

    private static function kvRow(string $label, string $value): string
    {
        return '<tr>'
            . '<td style="padding:9px 10px;background:#fafbfc;border:1px solid #eef0f3;'
            . 'color:#8c8c8c;white-space:nowrap;width:110px;">' . self::h($label) . '</td>'
            . '<td style="padding:9px 10px;border:1px solid #eef0f3;word-break:break-all;'
            . 'font-family:Menlo,Consolas,monospace;">' . self::h($value) . '</td>'
            . '</tr>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
