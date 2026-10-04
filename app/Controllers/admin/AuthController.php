<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Admin;
use App\Models\Setting;

/**
 * 后台登录
 */
class AuthController extends BaseController
{
    public function login(): void
    {
        if (Auth::checkAdmin()) {
            redirect(url('admin/index'));
        }

        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            Auth::verifyCsrf();
            $username = (string)input('username');
            $password = (string)($_POST['password'] ?? '');

            if (Auth::tooManyAttempts('admin_' . $username, 5, 900)) {
                flash_set('danger', '尝试次数过多，请 15 分钟后再试');
                redirect(url('admin/auth/login'));
            }

            $admin = Admin::findByUsername($username);
            if (!$admin || !Auth::verify($password, $admin['password'])) {
                Auth::hitAttempt('admin_' . $username);
                flash_set('danger', '用户名或密码错误');
                redirect(url('admin/auth/login'));
            }
            if ((int)$admin['status'] !== 1) {
                flash_set('danger', '该管理员账号已被禁用');
                redirect(url('admin/auth/login'));
            }

            Auth::clearAttempt('admin_' . $username);
            Auth::loginAdmin((int)$admin['id']);
            Admin::updateLastLogin((int)$admin['id']);
            log_write('admin_login', '管理员登录：' . $username, ['aid' => (int)$admin['id']]);
            flash_set('success', '登录成功');
            redirect(url('admin/index'));
        }

        $this->view('admin/login', ['title' => '管理后台登录 - ' . Setting::get('site_name')], '');
    }

    public function logout(): void
    {
        Auth::logoutAdmin();
        flash_set('success', '已退出管理后台');
        redirect(url('admin/auth/login'));
    }
}
