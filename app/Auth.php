<?php

namespace App;

/**
 * 认证：用户登录态、管理员登录态、CSRF 防护
 */
class Auth
{
    private const USER_KEY  = '_uid';
    private const ADMIN_KEY = '_aid';

    /** 当前用户行缓存（余额等字段变动后需 flushUserCache()） */
    private static ?array $userCache = null;

    // ---------------- 用户 ----------------

    public static function userId(): int
    {
        return (int)($_SESSION[self::USER_KEY] ?? 0);
    }

    public static function user(): ?array
    {
        $id = self::userId();
        if ($id <= 0) {
            return null;
        }
        if (self::$userCache === null || (int)self::$userCache['id'] !== $id) {
            self::$userCache = Database::instance()->first('SELECT * FROM ly_users WHERE id=? LIMIT 1', [$id]);
        }
        return self::$userCache ?: null;
    }

    /**
     * 清除用户行缓存
     *
     * 余额等字段变动后必须调用，否则同一请求内 Auth::user() 返回的是旧数据。
     */
    public static function flushUserCache(): void
    {
        self::$userCache = null;
    }

    public static function check(): bool
    {
        return self::userId() > 0;
    }

    public static function loginUser(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION[self::USER_KEY] = $id;
    }

    public static function logoutUser(): void
    {
        unset($_SESSION[self::USER_KEY]);
        session_regenerate_id(true);
    }

    /** 要求用户登录，否则跳转 */
    public static function requireUser(): void
    {
        if (!self::check()) {
            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
            flash_set('warning', '请先登录后再操作');
            redirect(url('auth/login'));
        }
        $u = self::user();
        if (!$u || (int)$u['status'] !== 1) {
            self::logoutUser();
            flash_set('danger', '账号已被禁用，请联系客服');
            redirect(url('auth/login'));
        }
    }

    // ---------------- 管理员 ----------------

    public static function adminId(): int
    {
        return (int)($_SESSION[self::ADMIN_KEY] ?? 0);
    }

    public static function admin(): ?array
    {
        $id = self::adminId();
        if ($id <= 0) {
            return null;
        }
        return Database::instance()->first('SELECT * FROM ly_admins WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    public static function checkAdmin(): bool
    {
        return self::adminId() > 0;
    }

    public static function loginAdmin(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION[self::ADMIN_KEY] = $id;
    }

    public static function logoutAdmin(): void
    {
        unset($_SESSION[self::ADMIN_KEY]);
        session_regenerate_id(true);
    }

    public static function requireAdmin(): void
    {
        if (!self::checkAdmin()) {
            flash_set('warning', '请先登录管理后台');
            redirect(url('admin/auth/login'));
        }
    }

    // ---------------- 密码 ----------------

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    // ---------------- CSRF ----------------

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::csrfToken()) . '">';
    }

    /**
     * 校验 CSRF（POST 请求）
     */
    public static function verifyCsrf(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        // 支付宝异步通知无 CSRF，由路由显式跳过
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals(self::csrfToken(), $token)) {
            http_response_code(419);
            exit('会话已过期或请求非法（CSRF 校验失败），请返回重新提交。');
        }
    }

    // ---------------- 登录限流 ----------------

    public static function tooManyAttempts(string $key, int $max = 5, int $window = 600): bool
    {
        $k = '_throttle_' . md5($key);
        $data = $_SESSION[$k] ?? ['n' => 0, 't' => 0];
        if (time() - $data['t'] > $window) {
            $data = ['n' => 0, 't' => time()];
        }
        return $data['n'] >= $max;
    }

    public static function hitAttempt(string $key): void
    {
        $k = '_throttle_' . md5($key);
        $data = $_SESSION[$k] ?? ['n' => 0, 't' => time()];
        if (time() - $data['t'] > 600) {
            $data = ['n' => 0, 't' => time()];
        }
        $data['n']++;
        $_SESSION[$k] = $data;
    }

    public static function clearAttempt(string $key): void
    {
        unset($_SESSION['_throttle_' . md5($key)]);
    }
}
