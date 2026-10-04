<?php /** 重置密码 */ ?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-head">
            <h1>设置新密码</h1>
            <p>请为您的账号设置一个新的登录密码</p>
        </div>

        <form method="post" action="<?= url('auth/reset') ?>">
            <?= \App\Auth::csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

            <div class="form-item">
                <label>新密码 <em>*</em></label>
                <input type="password" name="password" placeholder="至少 6 位字符" required minlength="6" autofocus>
            </div>

            <div class="form-item">
                <label>确认新密码 <em>*</em></label>
                <input type="password" name="password_confirm" placeholder="请再次输入新密码" required minlength="6">
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">确认重置</button>
        </form>

        <div class="auth-foot">
            想起密码了？<a href="<?= url('auth/login') ?>">返回登录</a>
        </div>
    </div>
</div>
