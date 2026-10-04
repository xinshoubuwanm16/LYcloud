<?php
/**
 * 应用引导文件：自动加载、配置、会话、站点配置缓存
 */

namespace App;

use App\Models\Setting;

// ---------- 常量兜底 ----------
if (!defined('LY_ROOT')) {
    define('LY_ROOT', dirname(__DIR__));
}

// ---------- 加载配置与函数库 ----------
require LY_ROOT . '/config/config.php';
require LY_ROOT . '/app/helpers.php';

// ---------- 自动加载：App\Foo\Bar -> app/Foo/Bar.php ----------
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = LY_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------- 时区 ----------
date_default_timezone_set(defined('LY_TIMEZONE') ? LY_TIMEZONE : 'Asia/Shanghai');

// ---------- 会话 ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('LYCLOUDSESSID');
    session_start();
}

// ---------- 载入站点配置 ----------
Setting::loadAll();
