<?php
/** 后台公共头部 */
$siteName = $__site['site_name'] ?? 'LY云计算';
$adminName = $__admin['username'] ?? 'admin';
$active = $__active ?? '';
$menu = [
    ['admin/index',            '控制台'],
    ['admin/product/list',     '商品管理'],
    ['admin/category/list',    '分类管理'],
    ['admin/stock/list',       '库存管理'],
    ['admin/stock/import',     '批量导入'],
    ['admin/order/list',       '订单管理'],
    ['admin/expire/list',      '到期管理'],
    ['admin/user/list',        '用户管理'],
    ['admin/redeem/list',      '兑换码'],
    ['admin/coupon/list',      '优惠券'],
    ['admin/setting/index',    '系统设置'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#ffb7c5">
<title><?= e($title ?? '管理后台') ?> - <?= e($siteName) ?> 管理后台</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
</head>
<body class="admin-body">

<aside class="admin-sidebar">
    <div class="sidebar-logo">
        <span class="logo-mark" aria-hidden="true"></span>
        <div>
            <b><?= e($siteName) ?></b>
            <em>管理后台 v<?= e(LY_VERSION) ?></em>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($menu as $i => $m): ?>
            <a href="<?= url($m[0]) ?>"<?= $active === $m[0] ? ' class="active"' : '' ?>>
                <i><?= sprintf('%02d', $i + 1) ?></i><span><?= $m[1] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <a href="<?= url('home/index') ?>" target="_blank">访问前台</a>
        <a href="<?= url('admin/auth/logout') ?>">退出登录</a>
    </div>
</aside>

<div class="admin-main">
    <header class="admin-topbar">
        <div class="tb-left">
            <button class="tb-toggle" onclick="document.body.classList.toggle('sidebar-open')" aria-label="菜单">≡</button>
            <h1><?= e($title ?? '管理后台') ?></h1>
        </div>
        <div class="tb-right">
            <span class="tb-time" id="tbTime"></span>
            <span class="tb-user"><?= e($adminName) ?></span>
        </div>
    </header>

    <div class="admin-content">
        <?php foreach (($__flashes ?? []) as $f): ?>
            <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>

        <?php require $file; ?>
    </div>
</div>

<script>
(function(){
    function tick(){
        var d = new Date();
        var p = function(n){ return n < 10 ? '0' + n : n; };
        var el = document.getElementById('tbTime');
        if (el) el.textContent = d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate())
            + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }
    tick(); setInterval(tick, 1000);
})();
</script>
<script src="<?= asset('assets/js/admin.js') ?>"></script>
</body>
</html>
