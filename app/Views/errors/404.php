<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 · 页面不存在</title>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>
<div class="error-page">
    <div class="error-code">404</div>
    <h1>页面不存在</h1>
    <p>你访问的地址没有对应页面，可能链接已失效或输入有误。</p>
    <div class="error-actions">
        <a class="btn btn-primary" href="<?= url('home/index') ?>">返回首页</a>
        <a class="btn btn-outline" href="<?= url('product/list') ?>">浏览商品</a>
    </div>
</div>
</body>
</html>
