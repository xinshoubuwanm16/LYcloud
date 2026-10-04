<?php

namespace App\Controllers;

use App\Auth;

/**
 * 控制器基类：视图渲染、CSRF
 */
abstract class BaseController
{
    /**
     * 渲染视图
     */
    protected function view(string $template, array $data = [], string $layout = 'layout/header'): void
    {
        $data['__flashes'] = flash_get();
        $data['__auth_user'] = Auth::user();
        $data['__csrf'] = Auth::csrfToken();
        $data['__site'] = \App\Models\Setting::all();

        extract($data, EXTR_SKIP);

        $file = LY_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('视图不存在：' . $template);
        }

        if ($layout !== '') {
            require LY_ROOT . '/app/Views/' . $layout . '.php';
        } else {
            require $file;
        }
    }

    /** 渲染纯视图（无 layout） */
    protected function viewRaw(string $template, array $data = []): void
    {
        $this->view($template, $data, '');
    }

    /** 后台视图 */
    protected function adminView(string $template, array $data = []): void
    {
        $data['__flashes'] = flash_get();
        $data['__admin'] = Auth::admin();
        $data['__csrf'] = Auth::csrfToken();
        $data['__site'] = \App\Models\Setting::all();
        $data['__active'] = $_GET['r'] ?? '';

        extract($data, EXTR_SKIP);
        $file = LY_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('视图不存在：' . $template);
        }
        require LY_ROOT . '/app/Views/layout/admin_header.php';
    }

    /** POST 请求校验 CSRF */
    protected function requirePost(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        Auth::verifyCsrf();
    }

    protected function requireGet(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
    }

    /** 记住表单旧值 */
    protected function rememberOld(array $keys): void
    {
        $_SESSION['_old'] = [];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $_SESSION['_old'][$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
            }
        }
    }

    protected function clearOld(): void
    {
        unset($_SESSION['_old']);
    }

    /** 返回 JSON 错误 */
    protected function jsonError(string $msg, int $code = 400): void
    {
        json_response(['code' => $code, 'msg' => $msg], $code);
    }

    protected function jsonOk(array $data = []): void
    {
        json_response(array_merge(['code' => 0, 'msg' => 'ok'], $data));
    }
}
