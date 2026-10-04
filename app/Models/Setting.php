<?php

namespace App\Models;

/**
 * 系统配置（键值对）
 */
class Setting
{
    private static array $cache = [];
    private static bool $loaded = false;

    /** 一次性载入全部配置 */
    public static function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }
        try {
            $rows = \App\Database::instance()->select('SELECT `k`,`v` FROM ly_settings');
            foreach ($rows as $r) {
                self::$cache[$r['k']] = $r['v'];
            }
            self::$loaded = true;
        } catch (\Throwable $e) {
            self::$loaded = false;
        }
    }

    public static function get(string $key, $default = '')
    {
        if (!self::$loaded) {
            self::loadAll();
        }
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        $db = \App\Database::instance();
        $exists = $db->first('SELECT `k` FROM ly_settings WHERE `k`=? LIMIT 1', [$key]);
        if ($exists) {
            $db->update('ly_settings', ['v' => $value, 'updated_at' => date('Y-m-d H:i:s')], '`k`=?', [$key]);
        } else {
            $db->insert('ly_settings', ['k' => $key, 'v' => $value]);
        }
        self::$cache[$key] = $value;
    }

    public static function setMany(array $data): void
    {
        foreach ($data as $k => $v) {
            self::set($k, $v);
        }
    }

    public static function all(): array
    {
        if (!self::$loaded) {
            self::loadAll();
        }
        return self::$cache;
    }

    /** 读取支付宝配置数组 */
    public static function alipay(): array
    {
        return [
            'mode'        => self::get('alipay_mode', 'qr'),
            'app_id'      => self::get('alipay_app_id', ''),
            'private_key' => self::get('alipay_private_key', ''),
            'public_key'  => self::get('alipay_public_key', ''),
            'gateway'     => self::get('alipay_gateway', 'sandbox'),
            'notify_url'  => self::get('alipay_notify_url', ''),
            'return_url'  => self::get('alipay_return_url', ''),
        ];
    }

    /** 支付宝是否已配置完成 */
    public static function alipayConfigured(): bool
    {
        $c = self::alipay();
        return $c['app_id'] !== '' && $c['private_key'] !== '' && $c['public_key'] !== '';
    }

    public static function demoPayEnabled(): bool
    {
        return (string)self::get('demo_pay_enabled', '1') === '1';
    }

    // ==================== 易支付配置 ====================

    /** 读取易支付配置数组 */
    public static function yipay(): array
    {
        return [
            'switch'     => (string)self::get('yipay_switch', '0') === '1',
            'api_url'    => (string)self::get('yipay_api_url', ''),
            'pid'        => (string)self::get('yipay_pid', ''),
            'key'        => (string)self::get('yipay_key', ''),
            'mode'       => self::get('yipay_mode', 'jump') === 'api' ? 'api' : 'jump',
            'channels'   => self::yipayChannels(),
            'notify_url' => self::get('yipay_notify_url', ''),
            'return_url' => self::get('yipay_return_url', ''),
        ];
    }

    /** 已启用的易支付子通道列表（'alipay' / 'wxpay' / 'qqpay'） */
    public static function yipayChannels(): array
    {
        $raw = (string)self::get('yipay_channels', 'alipay,wxpay');
        $all = ['alipay', 'wxpay', 'qqpay'];
        $out = [];
        foreach (explode(',', $raw) as $c) {
            $c = strtolower(trim($c));
            if (in_array($c, $all, true) && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** 易支付是否可用（开关打开 + 接口地址/PID/KEY 齐全 + 至少启用一个子通道） */
    public static function yipayEnabled(): bool
    {
        $c = self::yipay();
        return $c['switch']
            && trim($c['api_url']) !== ''
            && trim($c['pid']) !== ''
            && trim($c['key']) !== ''
            && $c['channels'] !== [];
    }

    // ==================== 捐赠支付（1.7.9） ====================

    /** 捐赠支付开关（后台「系统设置 → 捐赠收款码」控制） */
    public static function donateEnabled(): bool
    {
        return (string)self::get('donate_enabled', '0') === '1';
    }

    /** 捐赠支付说明文案（支付页/弹窗展示） */
    public static function donateDesc(): string
    {
        return trim((string)self::get('donate_desc', ''));
    }

    /**
     * 读取一张捐赠收款码
     *
     * @param string $which 'ali'=支付宝 / 'wechat'=微信
     * @return string 可访问的相对 URL（如 uploads/donate/xxx.png）；未上传或文件已丢失返回 ''
     */
    public static function donateQr(string $which): string
    {
        if ($which !== 'ali' && $which !== 'wechat') {
            return '';
        }
        $key = $which === 'wechat' ? 'donate_qr_wechat_path' : 'donate_qr_ali_path';
        $path = trim((string)self::get($key, ''));
        if ($path === '' || str_contains($path, '..') || !is_file(LY_ROOT . '/' . ltrim($path, '/'))) {
            return '';
        }
        return $path;
    }

    /** 捐赠支付是否就绪（启用 且 至少上传了一张收款码） */
    public static function donateReady(): bool
    {
        return self::donateEnabled()
            && (self::donateQr('ali') !== '' || self::donateQr('wechat') !== '');
    }

    // ==================== MNBT 主机开通配置 ====================

    /**
     * 读取 MNBT 配置数组
     *
     * MNBT（梦奈宝塔主机系统）用于「商品 → 支付成功 → 实时 API 开通主机」。
     * 认证为明文参数（无签名），因此生产环境务必使用 HTTPS。
     *
     * @return array{
     *   switch:bool, api_url:string, mn_bh:string, mn_key:string,
     *   mn_keye:string, mn_vs:string, timeout:int, connect_timeout:int,
     *   max_retry:int, default_prefix:string
     * }
     */
    public static function mnbt(): array
    {
        return [
            'switch'          => (string)self::get('mnbt_switch', '0') === '1',
            'api_url'         => (string)self::get('mnbt_api_url', ''),
            'mn_bh'           => (string)self::get('mnbt_bh', ''),
            'mn_key'          => (string)self::get('mnbt_key', ''),
            'mn_keye'         => (string)self::get('mnbt_keye', ''),
            'mn_vs'           => (string)self::get('mnbt_vs', '16'),
            'timeout'         => max(5, min(60, (int)self::get('mnbt_timeout', '15'))),
            'connect_timeout' => max(3, min(30, (int)self::get('mnbt_connect_timeout', '10'))),
            'max_retry'       => max(0, min(10, (int)self::get('mnbt_max_retry', '2'))),
            'default_prefix'  => (string)self::get('mnbt_default_prefix', 'ly'),
        ];
    }

    /**
     * MNBT 配置是否可用于开通主机
     * 需同时满足：开关打开 + 接口地址/宝塔编号/API密钥/调用密钥齐全
     */
    public static function mnbtConfigured(): bool
    {
        $c = self::mnbt();
        return $c['switch']
            && trim($c['api_url']) !== ''
            && trim($c['mn_bh']) !== ''
            && trim($c['mn_key']) !== ''
            && trim($c['mn_keye']) !== '';
    }

    /** 单笔订单自动开通的最大尝试次数（首次 + 重试） */
    public static function mnbtMaxRetry(): int
    {
        return self::mnbt()['max_retry'];
    }

    // ==================== 余额与兑换码配置 ====================

    /** 余额功能总开关（关闭后不可抵扣、不可兑换，余额仍可见） */
    public static function balanceEnabled(): bool
    {
        return (string)self::get('balance_enabled', '1') === '1';
    }

    /** 单张兑换码面额下限（0.01 起，支持 0.1 / 0.5 这类小额码） */
    public static function redeemMinAmount(): string
    {
        return \App\Models\BalanceLog::normalize(self::get('redeem_min_amount', '0.01'));
    }

    /** 单张兑换码面额上限 */
    public static function redeemMaxAmount(): string
    {
        return \App\Models\BalanceLog::normalize(self::get('redeem_max_amount', '99999.00'));
    }

    /** 前台余额页是否允许自助兑换（关闭则仅后台发码） */
    public static function redeemEnabled(): bool
    {
        return self::balanceEnabled() && (string)self::get('redeem_enabled', '1') === '1';
    }

    // ==================== 优惠券配置 ====================

    /** 优惠券功能总开关（关闭后商品页不显示用券控件、券不可用于结算） */
    public static function couponEnabled(): bool
    {
        return (string)self::get('coupon_enabled', '1') === '1';
    }

    /** 领券中心开关（关闭后前台不展示领券入口，我的券包仍可见） */
    public static function couponClaimEnabled(): bool
    {
        return self::couponEnabled() && (string)self::get('coupon_claim_enabled', '1') === '1';
    }

    // ==================== SMTP 邮件配置 ====================

    /** 读取 SMTP 配置数组 */
    public static function smtp(): array
    {
        return [
            'enabled'    => (string)self::get('smtp_enabled', '0') === '1',
            'host'       => (string)self::get('smtp_host', ''),
            'port'       => (int)self::get('smtp_port', '465'),
            'encryption' => (string)self::get('smtp_encryption', 'ssl'),
            'username'   => (string)self::get('smtp_username', ''),
            'password'   => (string)self::get('smtp_password', ''),
            'from_email' => (string)self::get('smtp_from_email', ''),
            'from_name'  => (string)self::get('smtp_from_name', self::get('site_name', 'LY云计算')),
            'timeout'    => (int)self::get('smtp_timeout', '15'),
        ];
    }

    /** SMTP 是否可用（开关打开 + 关键字段齐全） */
    public static function smtpConfigured(): bool
    {
        $c = self::smtp();
        return $c['enabled']
            && $c['host'] !== ''
            && $c['username'] !== ''
            && $c['password'] !== ''
            && filter_var($c['from_email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    // ==================== 注册邮箱域名白名单 ====================

    /**
     * 规范化域名列表：小写、去空白、去前导点、去重
     * @return string[]
     */
    public static function normalizeDomains(string $raw): array
    {
        $parts = preg_split('/[\s,，;；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return [];
        }
        $out = [];
        foreach ($parts as $d) {
            $d = strtolower(trim($d, " \t."));
            if ($d !== '') {
                $out[$d] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * 允许注册的邮箱域名列表；返回空数组表示不限制
     * @return string[]
     */
    public static function registerDomains(): array
    {
        return self::normalizeDomains((string)self::get('register_email_domains', 'qq.com,foxmail.com'));
    }

    /**
     * 校验邮箱域名是否在白名单内
     * 注意：不能用 str_ends_with 裸判断，否则 notqq.com 会命中 qq.com
     */
    public static function emailDomainAllowed(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(substr($email, $at + 1));
        if ($domain === '') {
            return false;
        }

        $allowed = self::registerDomains();
        if (!$allowed) {
            return true; // 白名单为空 = 不限制
        }

        foreach ($allowed as $d) {
            // 完全相等，或作为子域出现（.qq.com 结尾）
            if ($domain === $d || substr($domain, -(strlen($d) + 1)) === '.' . $d) {
                return true;
            }
        }
        return false;
    }

    /** 注册是否要求邮箱验证码（实际生效还需 SMTP 已配置） */
    public static function emailVerifyRequired(): bool
    {
        return (string)self::get('register_email_verify', '1') === '1';
    }

    /** 发送邮箱验证码前是否需要图形验证码（人机校验） */
    public static function captchaRequired(): bool
    {
        return (string)self::get('captcha_enabled', '1') === '1';
    }

    /** 注册是否真正强制验证码：开关打开 且 SMTP 可用 */
    public static function emailVerifyEnforced(): bool
    {
        return self::emailVerifyRequired() && self::smtpConfigured();
    }
}
