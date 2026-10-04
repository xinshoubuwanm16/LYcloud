<?php /** 商品列表（1.7.8 起支持分类筛选） */
$categories   = $categories ?? [];
$currentCat   = $currentCat ?? null;
$currentCatId = (int)($currentCatId ?? 0);
$catBase = \App\Models\Category::class;
?>
<div class="wrap breadcrumb">
    <a href="<?= url('home/index') ?>">首页</a><span>/</span>
    <?php if ($currentCat): ?>
        <a href="<?= url('product/list') ?>">全部商品</a><span>/</span><em><?= e($catBase::nameOf($currentCat)) ?></em>
    <?php else: ?>
        <em>全部商品</em>
    <?php endif; ?>
</div>

<div class="wrap">
    <div class="section-head left">
        <h2><?= $currentCat ? e($catBase::nameOf($currentCat)) : '全部商品' ?></h2>
        <p>
            <?= $currentCat
                ? e($catBase::nameOf($currentCat)) . '分类下共 ' . count($products) . ' 个在售套餐'
                : '共 ' . count($products) . ' 个在售套餐' ?>
        </p>
    </div>

    <?php if ($categories): ?>
    <nav class="cat-tabs" aria-label="商品分类筛选">
        <a class="cat-tab<?= $currentCatId === 0 ? ' is-active' : '' ?>"
           href="<?= url('product/list') ?>">全部</a>
        <?php foreach ($categories as $c): ?>
            <?php
                $style = $catBase::colorStyle($c);
                $ico   = $catBase::iconPath((string)$c['icon']);
                $active = $currentCatId === (int)$c['id'];
            ?>
            <a class="cat-tab<?= $active ? ' is-active' : '' ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?>
               href="<?= url('product/list', ['category' => (int)$c['id']]) ?>">
                <?php if ($ico !== ''): ?>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><?= $ico /* 白名单键，路径为程序内置常量 */ ?></svg>
                <?php endif; ?>
                <?= e($c['name']) ?>
                <i><?= (int)$c['product_count'] ?></i>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if (!$products): ?>
        <?php if ($currentCat): ?>
            <div class="empty-state">该分类下暂无在售商品<br><a class="btn btn-outline" style="margin-top:16px" href="<?= url('product/list') ?>">查看全部商品</a></div>
        <?php else: ?>
            <div class="empty-state">暂无上架商品</div>
        <?php endif; ?>
    <?php else: ?>
    <div class="product-grid">
        <?php foreach ($products as $p):
            $tags = array_filter(array_map('trim', explode(',', $p['tags'])));
            $available = (int)$p['stock_mode'] === 1 ? $p['stock_count'] : 9999;
        ?>
        <div class="product-card">
            <div class="product-head">
                <h3><?= e($p['name']) ?></h3>
                <?php if ($p['subtitle'] !== ''): ?><p class="product-subtitle"><?= e($p['subtitle']) ?></p><?php endif; ?>
            </div>
            <?php if ($tags): ?>
            <div class="product-tags">
                <?php foreach ($tags as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="product-price">
                <span class="cur">¥</span><span class="num"><?= money($p['price']) ?></span>
                <?php if ($p['original_price'] > $p['price']): ?>
                    <span class="origin">¥<?= money($p['original_price']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($p['spec'] !== ''): ?><div class="product-spec"><?= e($p['spec']) ?></div><?php endif; ?>
            <ul class="product-meta">
                <li><span>可用库存</span><b class="<?= $available <= 0 ? 'text-danger' : 'text-ok' ?>"><?= (int)$p['stock_mode'] === 1 ? $available . ' 台' : '不限量' ?></b></li>
                <li><span>发货方式</span><b><?= (int)$p['auto_deliver'] === 1 ? '自动发货' : '人工发货' ?></b></li>
            </ul>
            <a class="btn btn-primary btn-block" href="<?= url('product/show', ['id' => (int)$p['id']]) ?>">
                <?= ($available <= 0 && (int)$p['stock_mode'] === 1) ? '暂时缺货' : '立即购买' ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
