<?php
/** 前台公共头部 */
$siteName = $__site['site_name'] ?? 'LY云计算';
$pageTitle = $title ?? $siteName;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#ffb7c5">
<title><?= e($pageTitle) ?></title>
<meta name="keywords" content="<?= e($__site['site_keywords'] ?? '') ?>">
<meta name="description" content="<?= e($__site['site_description'] ?? '') ?>">
<link rel="icon" href="<?= asset('favicon.ico') ?>" sizes="any">
<link rel="apple-touch-icon" href="<?= asset('assets/img/logo-mark@2x.png') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<script>
/* 主题预设（1.7.11）：必须在 CSS 之后、body 渲染之前同步执行，
   否则会先闪一下默认樱花粉再跳到蓝白猫娘。
   优先读访客本地偏好（localStorage.ly_theme），无则跟随系统深色偏好。
   仅两个值：'sakura'（默认）| 'bluecat' */
(function () {
    try {
        var t = localStorage.getItem('ly_theme');
        if (t !== 'bluecat' && t !== 'sakura') { t = 'sakura'; }
        if (t === 'bluecat') {
            document.documentElement.setAttribute('data-theme', 'bluecat');
        }
    } catch (e) { /* 隐私模式下 storage 不可用：保持默认主题 */ }
})();
</script>
</head>
<body>

<header class="site-header">
    <div class="wrap header-inner">
        <a class="logo" href="<?= url('home/index') ?>">
            <span class="logo-mark" aria-hidden="true"></span>
            <span class="logo-text"><?= e($siteName) ?></span>
        </a>

        <nav class="main-nav">
            <a href="<?= url('home/index') ?>"<?= strpos($_GET['r'] ?? '', 'home') === 0 ? ' class="active"' : '' ?>>首页</a>
            <a href="<?= url('product/list') ?>">全部商品</a>
            <?php if ($__auth_user): ?>
                <a href="<?= url('order/list') ?>"<?= strpos($_GET['r'] ?? '', 'order') === 0 ? ' class="active"' : '' ?>>我的订单</a>
                <a href="<?= url('user/coupons') ?>"<?= strpos($_GET['r'] ?? '', 'user/coupons') === 0 ? ' class="active"' : '' ?>>我的优惠券</a>
                <a href="<?= url('user/balance') ?>"<?= strpos($_GET['r'] ?? '', 'user/') === 0 && strpos($_GET['r'] ?? '', 'user/coupons') !== 0 ? ' class="active"' : '' ?>>我的余额</a>
            <?php endif; ?>
            <a href="<?= url('home/index') ?>#faq">常见问题</a>

            <!-- 移动端抽屉用户区块（桌面端隐藏） -->
            <?php if ($__auth_user): ?>
                <span class="nav-user-name"><?= e($__auth_user['nickname'] ?: $__auth_user['email']) ?></span>
                <a href="<?= url('user/balance') ?>">我的余额</a>
                <a href="<?= url('auth/logout') ?>">退出登录</a>
            <?php else: ?>
                <span class="nav-user-name nav-register-wrap"><a class="btn btn-primary btn-block" href="<?= url('auth/register') ?>">注册账号</a></span>
            <?php endif; ?>
        </nav>

        <div class="header-actions">
            <button type="button" class="theme-toggle" id="themeToggle"
                    title="切换主题：樱花粉 / 蓝白猫娘" aria-label="切换主题">
                <span class="tt-ico tt-ico-sakura" aria-hidden="true">🌸</span>
                <span class="tt-ico tt-ico-cat" aria-hidden="true">🐱</span>
                <span class="tt-label" data-theme-label>主题</span>
            </button>
            <?php if ($__auth_user): ?>
                <span class="user-chip"><?= e($__auth_user['nickname'] ?: $__auth_user['email']) ?></span>
                <a class="balance-chip" href="<?= url('user/balance') ?>" title="我的余额">
                    余额 <b data-balance-slot>¥<?= money($__auth_user['balance'] ?? '0.00') ?></b>
                </a>
                <a class="btn btn-ghost hide-m" href="<?= url('user/coupons') ?>">我的优惠券</a>
                <a class="btn btn-ghost hide-m" href="<?= url('order/list') ?>">我的订单</a>
                <a class="btn btn-ghost hide-m" href="<?= url('auth/logout') ?>">退出</a>
            <?php else: ?>
                <a class="btn btn-ghost" href="<?= url('auth/login') ?>">登录</a>
                <a class="btn btn-primary hide-m" href="<?= url('auth/register') ?>">注册账号</a>
            <?php endif; ?>
        </div>

        <button class="nav-toggle" onclick="document.body.classList.toggle('nav-open')" aria-label="菜单">
            <svg class="ico-menu" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            <svg class="ico-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
</header>
<div class="nav-mask" onclick="document.body.classList.remove('nav-open')"></div>

<?php if (!empty($__site['site_announce'])):
    /* 网站弹窗公告（1.7.0）：打开网站弹出居中弹窗。
       内容 md5 作为关闭记忆键：管理员更新公告后 hash 变化，所有访客自动重新看到 */
    $annHash = md5((string)$__site['site_announce']);
?>
<div class="modal-mask" id="announceModal" style="display:none">
    <div class="announce-modal" role="dialog" aria-modal="true" aria-label="网站公告">
        <button type="button" class="am-close" aria-label="关闭公告"
                onclick="LYAnn.close('<?= $annHash ?>')">×</button>
        <div class="am-head">网站公告</div>
        <div class="am-body"><?= nl2br(e($__site['site_announce'])) ?></div>
        <div class="am-foot">
            <button type="button" class="btn btn-primary btn-sm" onclick="LYAnn.close('<?= $annHash ?>')">我知道了</button>
        </div>
    </div>
</div>
<script>
(function () {
    var m = document.getElementById('announceModal');
    if (!m) return;
    try {
        // 访客已关闭过「同一条」公告：不弹（脚本紧跟 DOM，无闪烁）
        if (sessionStorage.getItem('ly_announce_closed') === '<?= $annHash ?>') return;
    } catch (e) { /* 隐私模式禁用 storage 时忽略，公告照常弹出 */ }
    m.style.display = 'flex';
    window.LYAnn = {
        close: function (h) {
            var x = document.getElementById('announceModal');
            if (x) x.style.display = 'none';
            try { sessionStorage.setItem('ly_announce_closed', h); } catch (e) {}
        }
    };
})();
</script>
<?php endif; ?>

<div class="flash-wrap wrap">
    <?php foreach (($__flashes ?? []) as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
</div>

<main class="site-main">
<?php require $file; ?>
</main>

<footer class="site-footer" id="faq">
    <div class="wrap footer-grid">
        <div>
            <div class="logo">
                <span class="logo-mark" aria-hidden="true"></span>
                <span class="logo-text"><?= e($siteName) ?></span>
            </div>
            <p class="footer-desc">
                提供 IPv6 宝塔面板主机。交付信息由系统自动分配，可在「我的订单」中查看。
            </p>
        </div>

        <div>
            <h4>快速导航</h4>
            <a href="<?= url('home/index') ?>">网站首页</a>
            <a href="<?= url('product/list') ?>">全部商品</a>
            <a href="<?= url('order/list') ?>">我的订单</a>
            <a href="<?= url('auth/register') ?>">注册账号</a>
        </div>

        <div>
            <h4>常见问题</h4>
            <p class="qa"><strong>付款后多久发货？</strong></p>
            <p class="qa">自动发货套餐在支付成功后立即分配，人工发货套餐由客服处理。</p>
            <p class="qa"><strong>面板信息在哪查看？</strong></p>
            <p class="qa">登录后进入「我的订单」，在订单详情页查看并一键复制。</p>
        </div>

        <div>
            <h4>联系我们</h4>
            <?php if (!empty($__site['site_contact_qq'])): ?>
                <p class="qa">QQ：<?= e($__site['site_contact_qq']) ?></p>
            <?php endif; ?>
            <?php if (!empty($__site['site_contact_tg'])): ?>
                <p class="qa">Telegram：<?= e($__site['site_contact_tg']) ?></p>
            <?php endif; ?>
            <p class="qa">下单前如有疑问，请先联系客服确认配置与网络环境。</p>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="wrap">
            <span>© <?= date('Y') ?> <?= e($siteName) ?> · 保留所有权利</span>
            <?php if (!empty($__site['site_icp'])): ?>
                <span><?= e($__site['site_icp']) ?></span>
            <?php endif; ?>
        </div>
    </div>
</footer>

<script>
/* 主题切换（1.7.11）：点击在「樱花粉 ⇄ 蓝白猫娘」间切换，写入 localStorage 记忆 */
(function () {
    var root = document.documentElement;
    var btn  = document.getElementById('themeToggle');
    var lbl  = btn ? btn.querySelector('[data-theme-label]') : null;

    function current() {
        return root.getAttribute('data-theme') === 'bluecat' ? 'bluecat' : 'sakura';
    }
    function syncLabel() {
        if (lbl) { lbl.textContent = current() === 'bluecat' ? '蓝白猫娘' : '樱花粉'; }
    }
    function apply(t) {
        if (t === 'bluecat') {
            root.setAttribute('data-theme', 'bluecat');
        } else {
            root.removeAttribute('data-theme');
        }
        try { localStorage.setItem('ly_theme', t); } catch (e) {}
        syncLabel();
    }
    syncLabel();
    if (btn) {
        btn.addEventListener('click', function () {
            apply(current() === 'bluecat' ? 'sakura' : 'bluecat');
        });
    }
})();
</script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
