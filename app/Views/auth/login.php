<?php /** 用户登录 */ ?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-head">
            <h1>用户登录</h1>
            <p>登录后可查看订单与面板登录信息</p>
        </div>

        <form method="post" action="<?= url('auth/login') ?>">
            <?= \App\Auth::csrfField() ?>

            <div class="form-item">
                <label>邮箱地址</label>
                <input type="email" name="email" value="<?= e(old('email')) ?>" placeholder="you@example.com" required autofocus>
            </div>

            <div class="form-item">
                <label>登录密码</label>
                <input type="password" name="password" placeholder="请输入密码" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">登 录</button>
        </form>

        <div class="auth-foot">
            还没有账号？<a href="<?= url('auth/register') ?>">立即注册</a>
            &nbsp;·&nbsp;
            <a href="<?= url('auth/forgot') ?>">忘记密码？</a>
        </div>
    </div>
</div>
