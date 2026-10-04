<?php

namespace App\Controllers;

use App\Auth;
use App\Mail\MailService;
use App\Models\EmailCode;
use App\Models\PasswordReset;
use App\Models\Setting;
use App\Models\User;

/**
 * 前台用户认证：注册 / 登录 / 登出
 */
class AuthController extends BaseController
{
    public function login(): void
    {
        if (Auth::check()) {
            redirect(url('order/list'));
        }

        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            Auth::verifyCsrf();
            $email    = strtolower((string)input('email'));
            $password = (string)($_POST['password'] ?? '');
            $this->rememberOld(['email']);

            if ($email === '' || $password === '') {
                flash_set('danger', '请填写邮箱和密码');
                redirect(url('auth/login'));
            }
            if (Auth::tooManyAttempts('login_' . $email, 5, 600)) {
                flash_set('danger', '登录失败次数过多，请 10 分钟后再试');
                redirect(url('auth/login'));
            }

            $user = User::findByEmail($email);
            if (!$user || !Auth::verify($password, $user['password'])) {
                Auth::hitAttempt('login_' . $email);
                flash_set('danger', '邮箱或密码错误');
                redirect(url('auth/login'));
            }
            if ((int)$user['status'] !== 1) {
                flash_set('danger', '账号已被禁用，请联系客服');
                redirect(url('auth/login'));
            }

            Auth::clearAttempt('login_' . $email);
            Auth::loginUser((int)$user['id']);
            User::updateLastLogin((int)$user['id']);
            $this->clearOld();
            log_write('user_login', '用户登录：' . $email, ['uid' => (int)$user['id']]);

            $intended = $_SESSION['_intended'] ?? '';
            unset($_SESSION['_intended']);
            flash_set('success', '登录成功，欢迎回来！');
            redirect($intended ?: url('order/list'));
        }

        $this->view('auth/login', ['title' => '用户登录 - ' . Setting::get('site_name')]);
    }

    public function register(): void
    {
        if (Auth::check()) {
            redirect(url('order/list'));
        }

        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            Auth::verifyCsrf();
            $email    = strtolower((string)input('email'));
            $password = (string)($_POST['password'] ?? '');
            $confirm  = (string)($_POST['password_confirm'] ?? '');
            $nickname = (string)input('nickname');
            $code     = trim((string)input('email_code'));
            $this->rememberOld(['email', 'nickname']);

            $errors = [];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = '请输入有效的邮箱地址';
            } elseif (!Setting::emailDomainAllowed($email)) {
                $domains = Setting::registerDomains();
                $errors[] = $domains
                    ? '仅支持以下邮箱注册：' . implode('、', $domains)
                    : '该邮箱域名不允许注册';
            }
            if (strlen($password) < 6) {
                $errors[] = '密码至少 6 位';
            }
            if ($password !== $confirm) {
                $errors[] = '两次输入的密码不一致';
            }
            if (User::findByEmail($email)) {
                $errors[] = '该邮箱已被注册';
            }

            // 邮箱验证码（仅在 SMTP 已配置且开关打开时强制）
            $needCode = Setting::emailVerifyEnforced();
            if ($needCode && !$errors) {
                if ($code === '') {
                    $errors[] = '请填写邮箱验证码';
                } else {
                    $r = EmailCode::verify($email, $code, EmailCode::PURPOSE_REGISTER);
                    if (!$r['ok']) {
                        $errors[] = $r['msg'];
                    }
                }
            }

            if ($errors) {
                foreach ($errors as $e) {
                    flash_set('danger', $e);
                }
                redirect(url('auth/register'));
            }

            $uid = User::create($email, $password, mb_substr($nickname, 0, 30), $needCode);
            Auth::loginUser($uid);
            User::updateLastLogin($uid);
            $this->clearOld();
            log_write('user_register', '新用户注册：' . $email, ['uid' => $uid]);
            flash_set('success', '注册成功，欢迎使用 ' . Setting::get('site_name', 'LY云计算') . '！');
            redirect(url('order/list'));
        }

        $this->view('auth/register', [
            'title'       => '注册账号 - ' . Setting::get('site_name'),
            'domains'     => Setting::registerDomains(),
            'needCode'    => Setting::emailVerifyEnforced(),
            'needCaptcha' => Setting::emailVerifyEnforced() && Setting::captchaRequired(),
        ]);
    }

    /**
     * 图形验证码图片（GET，每次请求生成新码）
     */
    public function captcha(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $code = \App\Captcha\Captcha::issue();
        \App\Captcha\Captcha::noStoreHeaders();
        echo \App\Captcha\Captcha::renderImage($code);
        exit;
    }

    /**
     * 发送注册邮箱验证码（AJAX）
     */
    public function sendCode(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            $this->jsonError('请求方式不正确', 405);
        }

        // 这是 AJAX 接口，CSRF 失败要返回 JSON 而非纯文本
        $token = (string)($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals(Auth::csrfToken(), $token)) {
            $this->jsonError('会话已过期，请刷新页面后重试', 419);
        }

        if (Auth::check()) {
            $this->jsonError('您已登录，无需注册', 400);
        }

        // 图形验证码：人机校验前置，防止邮箱验证码接口被脚本刷取
        if (Setting::captchaRequired()) {
            $captcha = trim((string)($_POST['captcha'] ?? ''));
            if ($captcha === '') {
                $this->jsonError('请输入图形验证码');
            }
            if (!\App\Captcha\Captcha::verify($captcha)) {
                $this->jsonError('图形验证码不正确或已过期，请点击图片换一张', 422);
            }
        }

        $email = strtolower((string)input('email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonError('请输入有效的邮箱地址');
        }
        if (!Setting::emailDomainAllowed($email)) {
            $domains = Setting::registerDomains();
            $this->jsonError($domains ? '仅支持以下邮箱：' . implode('、', $domains) : '该邮箱域名不允许注册');
        }
        if (User::findByEmail($email)) {
            $this->jsonError('该邮箱已被注册');
        }
        if (!Setting::smtpConfigured()) {
            $this->jsonError('邮件服务未配置，请联系管理员', 500);
        }

        // 限流：同 IP 每小时 10 次
        $ipKey = 'regcode_ip_' . md5(client_ip());
        if (Auth::tooManyAttempts($ipKey, 10, 3600)) {
            $this->jsonError('请求过于频繁，请稍后再试', 429);
        }

        $r = EmailCode::issue($email, EmailCode::PURPOSE_REGISTER, client_ip());
        if (!$r['ok']) {
            $this->jsonError($r['msg'] . ($r['retry_after'] > 0 ? '（' . $r['retry_after'] . ' 秒后可重试）' : ''), 429);
        }

        Auth::hitAttempt($ipKey);

        if (!MailService::sendVerifyCode($email, $r['code'], (int)(EmailCode::TTL / 60))) {
            $err = trim((string)MailService::lastError());
            log_write('regcode_mail_failed', '验证码邮件发送失败：' . $email, ['err' => $err]);
            // 真实原因直接透出（SMTP 错误不含密码等敏感信息），便于管理员远程报障排查
            $this->jsonError('验证码发送失败：' . mb_substr($err !== '' ? $err : '未知原因，请查看 storage/logs 日志', 0, 200), 500);
        }

        log_write('regcode_sent', '注册验证码已发送：' . $email);
        $this->jsonOk(['msg' => '验证码已发送至 ' . $email, 'retry_after' => $r['retry_after']]);
    }

    /**
     * 忘记密码：申请重置链接
     */
    public function forgot(): void
    {
        if (Auth::check()) {
            redirect(url('order/list'));
        }

        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            Auth::verifyCsrf();
            $email = strtolower((string)input('email'));
            $this->rememberOld(['email']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash_set('danger', '请输入有效的邮箱地址');
                redirect(url('auth/forgot'));
            }

            // 限流：同邮箱 10 分钟 3 次
            $key = 'forgot_' . md5($email);
            if (Auth::tooManyAttempts($key, 3, 600)) {
                flash_set('danger', '请求过于频繁，请 10 分钟后再试');
                redirect(url('auth/forgot'));
            }
            Auth::hitAttempt($key);

            $user = User::findByEmail($email);

            // 无论邮箱是否存在都给出相同提示，避免账号枚举
            if ($user && (int)$user['status'] === 1 && Setting::smtpConfigured()) {
                $token = PasswordReset::issue((int)$user['id'], $email, client_ip());
                $url   = base_url('index.php?r=auth/reset&token=' . $token);
                if (MailService::sendPasswordReset($email, $url, (int)(PasswordReset::TTL / 60))) {
                    log_write('password_reset_sent', '重置邮件已发送：' . $email, ['uid' => (int)$user['id']]);
                } else {
                    log_write('password_reset_mail_failed', '重置邮件发送失败：' . $email, ['err' => MailService::lastError()]);
                }
            } else {
                // 模拟相近耗时，进一步弱化时间侧信道
                usleep(random_int(150000, 350000));
                log_write('password_reset_ignored', '重置请求（用户不存在或未启用邮件）：' . $email);
            }

            $this->clearOld();
            flash_set('success', '如果该邮箱已注册，重置链接已发送，请查收邮件（含垃圾箱）');
            redirect(url('auth/login'));
        }

        $this->view('auth/forgot', [
            'title'     => '找回密码 - ' . Setting::get('site_name'),
            'mailReady' => Setting::smtpConfigured(),
        ]);
    }

    /**
     * 重置密码：校验令牌并设置新密码
     */
    public function reset(): void
    {
        $token = trim((string)input('token'));
        $row   = PasswordReset::findValid($token);

        if (!$row) {
            flash_set('danger', '重置链接无效或已过期，请重新申请');
            redirect(url('auth/forgot'));
        }

        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            Auth::verifyCsrf();

            $password = (string)($_POST['password'] ?? '');
            $confirm  = (string)($_POST['password_confirm'] ?? '');

            $errors = [];
            if (strlen($password) < 6) {
                $errors[] = '密码至少 6 位';
            }
            if ($password !== $confirm) {
                $errors[] = '两次输入的密码不一致';
            }

            if ($errors) {
                foreach ($errors as $e) {
                    flash_set('danger', $e);
                }
                redirect(url('auth/reset', ['token' => $token]));
            }

            $userId = (int)$row['user_id'];
            User::resetPassword($userId, $password);
            PasswordReset::consume($token);
            PasswordReset::invalidateAllForUser($userId);

            log_write('password_reset_done', '密码已重置：' . $row['email'], ['uid' => $userId]);
            flash_set('success', '密码已重置，请使用新密码登录');
            redirect(url('auth/login'));
        }

        $this->view('auth/reset', [
            'title' => '重置密码 - ' . Setting::get('site_name'),
            'token' => $token,
        ]);
    }

    public function logout(): void
    {
        Auth::logoutUser();
        flash_set('success', '您已安全退出');
        redirect(url('home/index'));
    }
}
