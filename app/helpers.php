<?php
/**
 * 通用辅助函数
 */

if (!function_exists('e')) {
    /** HTML 转义输出 */
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    /** 读取 config/config.php 中的配置项 */
    function config(string $key, $default = null)
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = defined('LY_CONFIG') ? LY_CONFIG : [];
        }
        return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
    }
}

if (!function_exists('setting')) {
    /** 读取数据库系统配置 */
    function setting(string $key, $default = '')
    {
        return \App\Models\Setting::get($key, $default);
    }
}

if (!function_exists('url')) {
    /** 生成站内路由地址 */
    function url(string $route = '', array $params = []): string
    {
        $route = trim($route, '/');
        $base  = rtrim(config('base_path', ''), '/');
        $u     = $base . '/' . ($route === '' ? '' : 'index.php?r=' . $route);
        if ($route === '') {
            $u = $base . '/';
        }
        if ($params) {
            $u .= (strpos($u, '?') === false ? '?' : '&') . http_build_query($params);
        }
        return $u;
    }
}

if (!function_exists('asset')) {
    /**
     * 静态资源地址（带版本号防缓存）
     * 1.5.2 起改用「文件修改时间」指纹：文件一更新 URL 自动变化，
     * 浏览器缓存立即失效——升级无需清浏览器缓存、无需改 LY_VERSION。
     * 文件不存在时回退到 LY_VERSION。
     */
    function asset(string $path): string
    {
        $base = rtrim(config('base_path', ''), '/');
        $rel  = ltrim($path, '/');
        $file = dirname(__DIR__) . '/' . $rel;
        $ver  = is_file($file) ? substr((string)filemtime($file), -8) . dechex(crc32($rel)) : LY_VERSION;
        return $base . '/' . $rel . '?v=' . $ver;
    }
}

if (!function_exists('base_url')) {
    /** 当前站点协议+域名（用于回调地址） */
    function base_url(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            $scheme = 'https';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . rtrim(config('base_path', ''), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('json_response')) {
    function json_response(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('input')) {
    /** 获取请求参数（已 trim） */
    function input(string $key, $default = '')
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }
}

if (!function_exists('old')) {
    /** 表单回填 */
    function old(string $key, $default = '')
    {
        $v = $_SESSION['_old'][$key] ?? $default;
        return is_string($v) ? $v : $default;
    }
}

if (!function_exists('flash_set')) {
    function flash_set(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
    }
}

if (!function_exists('flash_get')) {
    function flash_get(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}

if (!function_exists('money')) {
    /** 金额格式化 */
    function money($amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }
}

if (!function_exists('random_order_no')) {
    /** 生成商户订单号 */
    function random_order_no(): string
    {
        return date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = explode(',', $_SERVER[$k])[0];
                return substr(trim($ip), 0, 60);
            }
        }
        return '';
    }
}

if (!function_exists('log_write')) {
    /** 写入系统日志 */
    function log_write(string $type, string $message, array $extra = []): void
    {
        try {
            \App\Database::instance()->insert('ly_logs', [
                'type'    => $type,
                'message' => mb_substr($message, 0, 480),
                'extra'   => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
                'ip'      => client_ip(),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
        }
    }
}

if (!function_exists('active_class')) {
    function active_class(string $route, string $prefix = ''): string
    {
        $current = $_GET['r'] ?? '';
        $target  = $prefix !== '' ? $prefix : $route;
        return strpos($current, $target) === 0 ? ' class="active"' : '';
    }
}

if (!function_exists('paginate')) {
    /**
     * 生成分页 HTML
     */
    function paginate(int $total, int $perPage, int $page, string $route, array $query = []): string
    {
        $pages = (int)ceil($total / max(1, $perPage));
        if ($pages <= 1) {
            return '';
        }
        $html = '<nav class="pagination">';
        $mk = function ($p, $label, $disabled = false, $active = false) use ($route, $query) {
            $query['page'] = $p;
            $cls = $active ? 'page-item active' : ($disabled ? 'page-item disabled' : 'page-item');
            if ($disabled) {
                return '<span class="' . $cls . '"><span class="page-link">' . $label . '</span></span>';
            }
            return '<span class="' . $cls . '"><a class="page-link" href="'
                . e(url($route, $query)) . '">' . $label . '</a></span>';
        };
        $html .= $mk(max(1, $page - 1), '«', $page <= 1);
        $start = max(1, $page - 2);
        $end   = min($pages, $page + 2);
        if ($start > 1) {
            $html .= $mk(1, '1', false, $page === 1);
            if ($start > 2) {
                $html .= '<span class="page-item disabled"><span class="page-link">…</span></span>';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $html .= $mk($i, (string)$i, false, $i === $page);
        }
        if ($end < $pages) {
            if ($end < $pages - 1) {
                $html .= '<span class="page-item disabled"><span class="page-link">…</span></span>';
            }
            $html .= $mk($pages, (string)$pages, false, $page === $pages);
        }
        $html .= $mk(min($pages, $page + 1), '»', $page >= $pages);
        $html .= '</nav>';
        return $html;
    }
}

if (!function_exists('status_text')) {
    function status_text(int $status): string
    {
        $map = [
            0 => ['待支付', 'warn'],
            1 => ['已支付', 'info'],
            2 => ['已发货', 'ok'],
            3 => ['已关闭', 'muted'],
            4 => ['已退款', 'danger'],
        ];
        return $map[$status][0] ?? '未知';
    }
}

if (!function_exists('status_class')) {
    function status_class(int $status): string
    {
        $map = [0 => 'warn', 1 => 'info', 2 => 'ok', 3 => 'muted', 4 => 'danger'];
        return $map[$status] ?? 'muted';
    }
}

if (!function_exists('pay_channel_text')) {
    /** 支付通道显示名（含易支付子通道） */
    function pay_channel_text(string $channel): string
    {
        switch ($channel) {
            case 'qr':      return '支付宝当面付（扫码）';
            case 'page':    return '支付宝电脑网站支付';
            case 'balance': return '账户余额支付';
            case 'free':    return '免费领取（0 元）';
        }
        if (\App\Payment\YiPayGateway::isChannel($channel)) {
            return \App\Payment\YiPayGateway::channelLabel($channel);
        }
        return $channel === '' ? '—' : $channel;
    }
}

if (!function_exists('pay_channel_text_short')) {
    /** 支付通道简称（表格用） */
    function pay_channel_text_short(string $channel): string
    {
        if (\App\Payment\YiPayGateway::isChannel($channel)) {
            $t = \App\Payment\YiPayGateway::typeOfChannel($channel);
            return '易支付·' . (\App\Payment\YiPayGateway::CHANNEL_LABELS[$t] ?? $t);
        }
        switch ($channel) {
            case 'qr':      return '当面付';
            case 'page':    return '网站支付';
            case 'balance': return '余额';
            case 'donate':  return '捐赠';
            case 'free':    return '免费';
        }
        return $channel === '' ? '—' : $channel;
    }
}

if (!function_exists('mask_ipv6_host')) {
    /** 从面板链接中提取主机名 */
    function extract_host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: $url;
    }
}

if (!function_exists('order_auto_close')) {
    /**
     * 超时未支付订单自动关闭（惰性触发入口）
     *
     * 挂在首页/订单列表/支付页等高频入口，页面被访问时顺带清理超时订单；
     * 无访问时段由后台计划任务（cron/tick）兜底。
     * 任何异常都不得影响主流程展示。
     */
    function order_auto_close(): void
    {
        try {
            $minutes = (int)\App\Models\Setting::get('order_expire_min', '5');
            if ($minutes > 0) {
                \App\Models\Order::autoCloseExpired($minutes);
            }
        } catch (\Throwable $e) {
            if (function_exists('log_write')) {
                log_write('order_auto_close_error', '自动关单执行失败：' . $e->getMessage());
            }
        }

        // 到期提醒扫描（1.7.0）：无待提醒订单时内部一条索引查询即返回，零负担
        try {
            \App\Models\Order::remindDue(7);
        } catch (\Throwable $e) {
            if (function_exists('log_write')) {
                log_write('expire_remind_error', '到期提醒扫描失败：' . $e->getMessage());
            }
        }

        // MNBT 开通失败重试（1.7.6）：无待重试订单时一条索引查询即返回
        try {
            \App\Models\Order::retryMnbtDeliver(3);
        } catch (\Throwable $e) {
            if (function_exists('log_write')) {
                log_write('mnbt_retry_error', 'MNBT 开通重试扫描失败：' . $e->getMessage());
            }
        }
    }
}
