<?php /** 我的订单 */ ?>
<div class="wrap breadcrumb">
    <a href="<?= url('home/index') ?>">首页</a><span>/</span><em>我的订单</em>
</div>

<div class="wrap order-page">
    <div class="section-head left">
        <h2>我的订单</h2>
        <p>共 <?= (int)$total ?> 笔订单</p>
    </div>

    <?php if (!$orders): ?>
        <div class="empty-state">
            你还没有任何订单<br>
            <a class="btn btn-primary" href="<?= url('product/list') ?>" style="margin-top:16px">去看看商品</a>
        </div>
    <?php else: ?>
    <div class="order-list">
        <?php foreach ($orders as $o): ?>
        <div class="order-item">
            <div class="oi-main">
                <div class="oi-top">
                    <span class="oi-no">订单号：<?= e($o['order_no']) ?></span>
                    <span class="status-badge status-<?= status_class((int)$o['status']) ?>"><?= status_text((int)$o['status']) ?></span>
                </div>
                <div class="oi-product"><?= e($o['product_name']) ?></div>
                <div class="oi-meta">
                    <span>支付方式：<?= e(pay_channel_text_short((string)$o['pay_channel'])) ?></span>
                    <span>下单时间：<?= e($o['created_at']) ?></span>
                    <?php if (!empty($o['paid_at'])): ?>
                        <span>支付时间：<?= e($o['paid_at']) ?></span>
                    <?php endif; ?>
                    <?php if (in_array((int)$o['status'], [1, 2], true)): ?>
                        <span class="<?= !empty($o['expire_at']) && strtotime($o['expire_at']) < time() ? 'text-danger' : '' ?>">
                            <?= e(\App\Models\Order::expireView($o['expire_at'] ?? null)) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="oi-side">
                <div class="oi-amount">¥<?= money($o['amount']) ?></div>
                <div class="oi-actions">
                    <?php if ((int)$o['status'] === 0): ?>
                        <a class="btn btn-primary btn-sm" href="<?= url('order/pay', ['id' => (int)$o['id']]) ?>">去支付</a>
                    <?php else: ?>
                        <a class="btn btn-primary btn-sm" href="<?= url('order/detail', ['id' => (int)$o['id']]) ?>#panel">查看面板</a>
                    <?php endif; ?>
                    <a class="btn btn-ghost btn-sm" href="<?= url('order/detail', ['id' => (int)$o['id']]) ?>">订单详情</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?= paginate((int)$total, (int)$perPage, (int)$page, 'order/list') ?>
    <?php endif; ?>
</div>
