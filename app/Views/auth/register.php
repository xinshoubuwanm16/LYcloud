<?php
/** 用户注册 */
$domains  = $domains  ?? [];
$needCode = $needCode ?? false;
$needCaptcha = $needCaptcha ?? false;
$domainHint = $domains ? implode('、', $domains) : '';
?>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-head">
            <h1>注册账号</h1>
            <p>使用邮箱注册，即可下单购买宝塔面板主机</p>
        </div>

        <form method="post" action="<?= url('auth/register') ?>" id="registerForm">
            <?= \App\Auth::csrfField() ?>

            <div class="form-item">
                <label>邮箱地址 <em>*</em></label>
                <input type="email" name="email" id="regEmail" value="<?= e(old('email')) ?>"
                       placeholder="<?= $domainHint !== '' ? '请输入 ' . e($domainHint) . ' 邮箱' : 'you@example.com' ?>"
                       required autofocus>
                <small>
                    <?php if ($domainHint !== ''): ?>
                        仅支持 <strong><?= e($domainHint) ?></strong> 邮箱注册，用于登录与接收订单信息
                    <?php else: ?>
                        用于登录与接收订单信息
                    <?php endif; ?>
                </small>
            </div>

            <?php if ($needCode): ?>
            <?php if ($needCaptcha): ?>
            <div class="form-item">
                <label>图形验证码 <em>*</em></label>
                <div class="input-group">
                    <input type="text" name="captcha" id="regCaptcha" placeholder="不区分大小写"
                           maxlength="4" autocomplete="off" autocapitalize="off" spellcheck="false">
                    <img src="<?= url('auth/captcha') ?>" alt="图形验证码" id="captchaImg" class="captcha-img"
                         width="150" height="46" title="看不清？点击换一张">
                </div>
                <small>看不清？点击图片换一张（4 位字符，不区分大小写）</small>
            </div>
            <?php endif; ?>
            <div class="form-item">
                <label>邮箱验证码 <em>*</em></label>
                <div class="input-group">
                    <input type="text" name="email_code" id="regCode" placeholder="6 位数字"
                           maxlength="6" inputmode="numeric" autocomplete="off" required>
                    <button type="button" class="btn btn-outline" id="btnSendCode">获取验证码</button>
                </div>
                <small>验证码 10 分钟内有效，请勿泄露给他人</small>
            </div>
            <?php endif; ?>

            <div class="form-item">
                <label>昵称（选填）</label>
                <input type="text" name="nickname" value="<?= e(old('nickname')) ?>" placeholder="选填，用于订单页显示" maxlength="30">
            </div>

            <div class="form-item">
                <label>设置密码 <em>*</em></label>
                <input type="password" name="password" placeholder="至少 6 位字符" required minlength="6">
            </div>

            <div class="form-item">
                <label>确认密码 <em>*</em></label>
                <input type="password" name="password_confirm" placeholder="请再次输入密码" required minlength="6">
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">注 册</button>
        </form>

        <div class="auth-foot">
            已有账号？<a href="<?= url('auth/login') ?>">直接登录</a>
        </div>
    </div>
</div>

<?php if ($needCode): ?>
<script>
(function () {
    var btn    = document.getElementById('btnSendCode');
    var email  = document.getElementById('regEmail');
    var code   = document.getElementById('regCode');
    var form   = document.getElementById('registerForm');
    var csrf   = form ? form.querySelector('input[name="_token"]') : null;
    var capImg = document.getElementById('captchaImg');
    var capInp = document.getElementById('regCaptcha');
    if (!btn || !email || !csrf) return;

    var timer  = null;
    var left   = 0;

    /** 刷新图形验证码（校验一次性销毁后必须换新图） */
    function refreshCaptcha() {
        if (!capImg) return;
        capImg.src = '<?= url('auth/captcha') ?>' + (capImg.src.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
        if (capInp) { capInp.value = ''; capInp.focus(); }
    }
    if (capImg) capImg.addEventListener('click', refreshCaptcha);

    function tick() {
        if (left > 0) {
            btn.disabled = true;
            btn.textContent = left + ' 秒后重发';
            left--;
        } else {
            clearInterval(timer);
            timer = null;
            btn.disabled = false;
            btn.textContent = '获取验证码';
        }
    }

    function countdown(sec) {
        left = sec || 60;
        clearInterval(timer);
        tick();
        timer = setInterval(tick, 1000);
    }

    btn.addEventListener('click', function () {
        var v = (email.value || '').trim();
        if (!v) { window.LY ? LY.toast('请先填写邮箱地址', 'warning') : alert('请先填写邮箱地址'); email.focus(); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { window.LY ? LY.toast('邮箱格式不正确', 'warning') : alert('邮箱格式不正确'); email.focus(); return; }
        var cv = capInp ? (capInp.value || '').trim() : '';
        if (capInp && cv.length < 4) {
            window.LY ? LY.toast('请输入图形验证码', 'warning') : alert('请输入图形验证码');
            capInp.focus();
            return;
        }

        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = '发送中…';

        var body = new FormData();
        body.append('_token', csrf.value);
        body.append('email', v);
        if (capInp) body.append('captcha', cv);

        fetch('<?= url('auth/sendCode') ?>', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { code: 500, msg: '服务返回异常' }; }); })
            .then(function (j) {
                if (j && j.code === 0) {
                    countdown(j.retry_after || 60);
                    refreshCaptcha();
                    if (code) code.focus();
                    window.LY ? LY.toast(j.msg || '验证码已发送', 'success') : alert(j.msg || '验证码已发送');
                } else {
                    btn.disabled = false;
                    btn.textContent = original;
                    refreshCaptcha();
                    window.LY ? LY.toast((j && j.msg) ? j.msg : '发送失败，请稍后重试', 'danger') : alert((j && j.msg) ? j.msg : '发送失败，请稍后重试');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = original;
                refreshCaptcha();
                window.LY ? LY.toast('网络异常，请稍后重试', 'danger') : alert('网络异常，请稍后重试');
            });
    });
})();
</script>
<?php endif; ?>
