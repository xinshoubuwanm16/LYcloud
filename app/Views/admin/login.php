<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#ffb7c5">
<title>管理后台登录 - <?= e($__site['site_name'] ?? 'LY云计算') ?></title>
<link rel="icon" href="<?= asset('favicon.ico') ?>" sizes="any">
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
</head>
<body class="admin-login-body">

<div class="admin-login-card">
    <div class="al-logo">
        <span class="logo-mark" aria-hidden="true"></span>
        <div>
            <b><?= e($__site['site_name'] ?? 'LY云计算') ?></b>
            <em>管理后台</em>
        </div>
    </div>

    <h1>管理员登录</h1>
    <p class="al-sub">请使用管理员账号登录后台管理系统</p>

    <?php foreach (($__flashes ?? []) as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>

    <form method="post" action="<?= url('admin/auth/login') ?>">
        <?= \App\Auth::csrfField() ?>

        <div class="form-item">
            <label>管理员账号</label>
            <input type="text" name="username" placeholder="请输入账号" required autofocus>
        </div>

        <div class="form-item">
            <label>登录密码</label>
            <input type="password" name="password" placeholder="请输入密码" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">登录后台</button>
    </form>

    <div class="al-foot">
        <a href="<?= url('home/index') ?>">← 返回网站首页</a>
    </div>
</div>

</body>
</html>
