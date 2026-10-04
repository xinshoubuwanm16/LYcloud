<?php /** 忘记密码 */ ?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-head">
            <h1>找回密码</h1>
            <p>输入注册邮箱，我们将发送重置链接</p>
        </div>

        <?php if (empty($mailReady)): ?>
            <div class="notice notice-warning">
                站点尚未配置邮件服务，暂时无法自助找回密码，请联系客服处理。
            </div>
        <?php endif; ?>

        <form method="post" action="<?= url('auth/forgot') ?>">
            <?= \App\Auth::csrfField() ?>

            <div class="form-item">
                <label>注册邮箱 <em>*</em></label>
                <input type="email" name="email" value="<?= e(old('email')) ?>"
                       placeholder="请输入注册时使用的邮箱" required autofocus>
                <small>重置链接 30 分钟内有效，且只能使用一次</small>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" <?= empty($mailReady) ? 'disabled' : '' ?>>
                发送重置链接
            </button>
        </form>

        <div class="auth-foot">
            想起来了？<a href="<?= url('auth/login') ?>">返回登录</a>
            &nbsp;·&nbsp;
            <a href="<?= url('auth/register') ?>">注册账号</a>
        </div>
    </div>
</div>
