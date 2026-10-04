<?php
/**
 * ============================================================
 *  LY云计算 - IPv6宝塔面板主机购买系统
 *  入口文件 (Front Controller) - 置于网站根目录
 * ============================================================
 *  Nginx 伪静态:
 *      location / {
 *          try_files $uri $uri/ /index.php?$query_string;
 *      }
 * ============================================================
 */

define('LY_START', microtime(true));
define('LY_ROOT', __DIR__);

// 调试开关：正式环境请设为 false
define('LY_DEBUG', false);

if (LY_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

// ---------- 未安装则跳转安装向导 ----------
if (!is_file(LY_ROOT . '/config/config.php')) {
    if (!preg_match('#^/install#', $_SERVER['REQUEST_URI'] ?? '')) {
        header('Location: /install/index.php');
        exit;
    }
    require LY_ROOT . '/install/index.php';
    exit;
}

require LY_ROOT . '/app/bootstrap.php';

App\Router::dispatch();
