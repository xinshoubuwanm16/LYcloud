<?php

namespace App;

use App\Models\Setting;

/**
 * 轻量路由
 * 约定：?r=控制器/方法，默认 home/index
 *      /admin/xxx 自动映射到 admin\XxxController
 */
class Router
{
    /** 路由白名单：controller => 允许的 method */
    private const ROUTES = [
        // 前台
        'home'          => ['index' => 'HomeController@index'],
        'product'       => ['show' => 'HomeController@show', 'list' => 'HomeController@listing'],
        'auth'          => [
            'login'    => 'AuthController@login',
            'register' => 'AuthController@register',
            'sendCode' => 'AuthController@sendCode',
            'captcha'  => 'AuthController@captcha',
            'forgot'   => 'AuthController@forgot',
            'reset'    => 'AuthController@reset',
            'logout'   => 'AuthController@logout',
        ],
        'order'         => [
            'create'       => 'OrderController@create',
            'pay'          => 'OrderController@pay',
            'detail'       => 'OrderController@detail',
            'list'         => 'OrderController@listing',
            'queryStatus'  => 'OrderController@queryStatus',
            'mnbtlogin'    => 'OrderController@mnbtLogin',
            'claimDonate'  => 'OrderController@claimDonate',
        ],
        'pay'           => [
            'notify'   => 'PayController@notify',
            'return'   => 'PayController@returnUrl',
            'demo'     => 'PayController@demoPay',
            'yinotify' => 'PayController@yiNotify',
            'yireturn' => 'PayController@yiReturn',
        ],
        'panel'         => ['show' => 'OrderController@panel'],
        'cron'          => ['tick' => 'CronController@tick'],
        'user'          => [
            'balance'      => 'UserController@balance',
            'redeem'       => 'UserController@redeem',
            'coupons'      => 'UserController@coupons',
            'claimCoupon'  => 'UserController@claimCoupon',
            'couponQuote'  => 'UserController@couponQuote',
        ],

        // 后台
        'admin'             => ['index' => 'admin\\DashboardController@index'],
        'admin/auth'        => ['login' => 'admin\\AuthController@login', 'logout' => 'admin\\AuthController@logout'],
        'admin/product'     => [
            'list' => 'admin\\ProductController@listing',
            'form' => 'admin\\ProductController@form',
            'save' => 'admin\\ProductController@save',
            'toggle' => 'admin\\ProductController@toggle',
            'delete' => 'admin\\ProductController@delete',
        ],
        'admin/category'    => [
            'list' => 'admin\\CategoryController@listing',
            'form' => 'admin\\CategoryController@form',
            'save' => 'admin\\CategoryController@save',
            'toggle' => 'admin\\CategoryController@toggle',
            'delete' => 'admin\\CategoryController@delete',
            'move' => 'admin\\CategoryController@move',
        ],
        'admin/stock'       => [
            'list' => 'admin\\StockController@listing',
            'import' => 'admin\\StockController@import',
            'form' => 'admin\\StockController@form',
            'save' => 'admin\\StockController@save',
            'delete' => 'admin\\StockController@delete',
            'batchDelete' => 'admin\\StockController@batchDelete',
        ],
        'admin/order'       => [
            'list' => 'admin\\OrderController@listing',
            'detail' => 'admin\\OrderController@detail',
            'deliver' => 'admin\\OrderController@deliver',
            'close' => 'admin\\OrderController@close',
            'export' => 'admin\\OrderController@export',
            'retryMnbt' => 'admin\\OrderController@retryMnbt',
            'mnbtLogin' => 'admin\\OrderController@mnbtLogin',
            'confirmDonate' => 'admin\\OrderController@confirmDonate',
        ],
        'admin/expire'      => [
            'list'    => 'admin\\ExpireController@listing',
            'dispose' => 'admin\\ExpireController@dispose',
        ],
        'admin/user'        => [
            'list' => 'admin\\UserController@listing',
            'toggle' => 'admin\\UserController@toggle',
            'resetPassword' => 'admin\\UserController@resetPassword',
            'adjustBalance' => 'admin\\UserController@adjustBalance',
        ],
        'admin/redeem'      => [
            'list'        => 'admin\\RedeemController@listing',
            'generate'    => 'admin\\RedeemController@generate',
            'generated'   => 'admin\\RedeemController@generated',
            'void'        => 'admin\\RedeemController@void',
            'voidBatch'   => 'admin\\RedeemController@voidBatch',
            'cleanExpired' => 'admin\\RedeemController@cleanExpired',
        ],
        'admin/coupon'      => [
            'list'    => 'admin\\CouponController@listing',
            'form'    => 'admin\\CouponController@form',
            'save'    => 'admin\\CouponController@save',
            'toggle'  => 'admin\\CouponController@toggle',
            'delete'  => 'admin\\CouponController@delete',
            'grant'   => 'admin\\CouponController@grant',
            'doGrant' => 'admin\\CouponController@doGrant',
            'holders' => 'admin\\CouponController@holders',
        ],
        'admin/setting'     => [
            'index' => 'admin\\SettingController@index',
            'save'  => 'admin\\SettingController@save',
            'test'  => 'admin\\SettingController@test',
            'testMail' => 'admin\\SettingController@testMail',
            'testMnbt' => 'admin\\SettingController@testMnbt',
            'uploadDonate' => 'admin\\SettingController@uploadDonate',
            'removeDonate' => 'admin\\SettingController@removeDonate',
        ],
    ];

    public static function dispatch(): void
    {
        // 支持两种风格：?r=a/b  以及  PATH_INFO  /a/b
        $route = $_GET['r'] ?? '';
        if ($route === '') {
            $route = self::routeFromPath();
        }
        $route = trim((string)$route, '/');

        // 防止路径穿越
        if (strpos($route, '..') !== false) {
            self::abort404();
        }

        $segments = $route === '' ? [] : explode('/', $route);

        // 解析出 controller / action
        $action = 'index';
        $controllerKey = '';

        if ($segments === []) {
            $controllerKey = 'home';
            $action = 'index';
        } else {
            // 尝试从最长前缀匹配 admin/xxx 或单段
            $candidateKeys = [];
            for ($i = count($segments); $i >= 1; $i--) {
                $candidateKeys[] = implode('/', array_slice($segments, 0, $i));
            }
            foreach ($candidateKeys as $key) {
                if (isset(self::ROUTES[$key])) {
                    $controllerKey = $key;
                    $rest = array_slice($segments, count(explode('/', $key)));
                    if ($rest) {
                        $action = array_shift($rest);
                    }
                    break;
                }
            }
            if ($controllerKey === '') {
                // 默认单段控制器
                $controllerKey = $segments[0];
                $action = $segments[1] ?? 'index';
            }
        }

        if (!isset(self::ROUTES[$controllerKey])) {
            self::abort404();
        }
        $map = self::ROUTES[$controllerKey];

        if (!isset($map[$action])) {
            self::abort404();
        }

        [$class, $method] = explode('@', $map[$action]);
        $class = 'App\\Controllers\\' . $class;

        if (!class_exists($class) || !method_exists($class, $method)) {
            self::abort404();
        }

        $controller = new $class();
        $controller->{$method}();
    }

    /** 通过 PATH_INFO 或 REQUEST_URI 解析路由（伪静态友好） */
    private static function routeFromPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $uri = parse_url($uri, PHP_URL_PATH) ?: '';
        $base = rtrim(config('base_path', ''), '/');
        if ($base !== '' && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }
        $uri = trim($uri, '/');
        // 去掉 index.php
        $uri = preg_replace('#^index\.php/?#' , '', $uri);
        return $uri;
    }

    private static function abort404(): void
    {
        http_response_code(404);
        $view = LY_ROOT . '/app/Views/errors/404.php';
        if (is_file($view)) {
            include $view;
        } else {
            echo '404 Not Found';
        }
        exit;
    }
}
