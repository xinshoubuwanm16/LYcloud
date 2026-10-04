<?php /** 后台控制台 */ 
$maxAmount = 0.0;
foreach ($trend as $t) { $maxAmount = max($maxAmount, $t['amount']); }
$statusMap = [];
foreach ($byStatus as $s) { $statusMap[(int)$s['status']] = (int)$s['n']; }
?>
<?php if (!empty($expire['expired'])): ?>
<div class="flash flash-danger" style="margin-bottom:16px;">
    有 <b><?= (int)$expire['expired'] ?></b> 台机器已到期，请登录面板删除回收资源。
    <a href="<?= url('admin/expire/list', ['kind' => 'expired']) ?>" style="color:inherit;font-weight:700;">前往到期管理 →</a>
</div>
<?php elseif (!empty($expire['due'])): ?>
<div class="flash flash-warning" style="margin-bottom:16px;">
    有 <b><?= (int)$expire['due'] ?></b> 台机器将在 7 天内到期，已自动邮件提醒买家。
    <a href="<?= url('admin/expire/list', ['kind' => 'due']) ?>" style="color:inherit;font-weight:700;">查看 →</a>
</div>
<?php endif; ?>
<?php if (!empty($mnbtAttn['total'])): ?>
<div class="flash flash-danger" style="margin-bottom:16px;">
    MNBT 主机开通异常：<?php if (!empty($mnbtAttn['retry'])): ?><b><?= (int)$mnbtAttn['retry'] ?></b> 单待重试<?php endif; ?>
    <?php if (!empty($mnbtAttn['retry']) && !empty($mnbtAttn['failed'])): ?>，<?php endif; ?>
    <?php if (!empty($mnbtAttn['failed'])): ?><b><?= (int)$mnbtAttn['failed'] ?></b> 单开通失败（已退款）<?php endif; ?>。
    请检查上游主机平台与「系统设置 → MNBT对接」配置。
    <a href="<?= url('admin/order/list', ['status' => '1']) ?>" style="color:inherit;font-weight:700;">前往订单管理 →</a>
</div>
<?php endif; ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="sc-icon blue">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/><path d="M17 7h4v4"/></svg>
        </div>
        <div class="sc-body">
            <span>累计销售额</span>
            <strong>¥<?= money($stats['income_total']) ?></strong>
            <em>今日 ¥<?= money($stats['income_today']) ?></em>
        </div>
    </div>
    <div class="stat-card">
        <div class="sc-icon green">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10a1 1 0 011 1v17l-3-2-3 2-3-2-3 2V4a1 1 0 011-1z"/><path d="M9 8h6M9 12h6"/></svg>
        </div>
        <div class="sc-body">
            <span>订单总数</span>
            <strong><?= number_format($stats['order_total']) ?></strong>
            <em>今日 <?= number_format($stats['order_today']) ?> 笔</em>
        </div>
    </div>
    <div class="stat-card">
        <div class="sc-icon orange">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/></svg>
        </div>
        <div class="sc-body">
            <span>可用库存</span>
            <strong class="<?= $stats['stock_avail'] < 10 ? 'text-danger' : '' ?>"><?= number_format($stats['stock_avail']) ?></strong>
            <em>已售 <?= number_format($stats['stock_sold']) ?> 条</em>
        </div>
    </div>
    <div class="stat-card">
        <div class="sc-icon purple">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0112 0"/><path d="M16 5.5a3 3 0 010 5.5"/><path d="M18 20a6 6 0 00-3-5.2"/></svg>
        </div>
        <div class="sc-body">
            <span>注册用户</span>
            <strong><?= number_format($stats['user_total']) ?></strong>
            <em>商品 <?= number_format($stats['product_total']) ?> 个</em>
        </div>
    </div>
</div>

<div class="grid-2col">
    <div class="card">
        <div class="card-head">
            <h3>近 7 日销售额</h3>
        </div>
        <div class="chart-bars">
            <?php foreach ($trend as $t): 
                $h = $maxAmount > 0 ? max(4, round($t['amount'] / $maxAmount * 100)) : 4;
            ?>
            <div class="bar-col" title="<?= e($t['date']) ?>：¥<?= money($t['amount']) ?>（<?= $t['count'] ?> 笔）">
                <span class="bar-val"><?= $t['amount'] > 0 ? money($t['amount']) : '' ?></span>
                <div class="bar" style="height:<?= $h ?>%"></div>
                <span class="bar-label"><?= e($t['label']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>订单状态分布</h3>
        </div>
        <div class="status-list">
            <?php foreach ([0,1,2,3,4] as $st): 
                $n = $statusMap[$st] ?? 0;
                $totalN = array_sum($statusMap) ?: 1;
                $pct = round($n / $totalN * 100, 1);
            ?>
            <div class="status-row">
                <span class="status-badge status-<?= status_class($st) ?>"><?= status_text($st) ?></span>
                <div class="status-bar-wrap">
                    <div class="status-bar status-bg-<?= status_class($st) ?>" style="width:<?= $pct ?>%"></div>
                </div>
                <b><?= $n ?></b>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($warning): ?>
<div class="card">
    <div class="card-head">
        <h3>库存预警（可用库存少于 3 条）</h3>
        <a class="btn btn-sm btn-primary" href="<?= url('admin/stock/import') ?>">立即补货</a>
    </div>
    <table class="table">
        <thead>
            <tr><th>商品名称</th><th>售价</th><th>可用库存</th><th>操作</th></tr>
        </thead>
        <tbody>
            <?php foreach ($warning as $w): ?>
            <tr>
                <td><?= e($w['name']) ?></td>
                <td class="text-price">¥<?= money($w['price']) ?></td>
                <td><span class="badge <?= $w['avail'] <= 0 ? 'badge-danger' : 'badge-warn' ?>"><?= (int)$w['avail'] ?> 条</span></td>
                <td>
                    <a class="btn btn-sm btn-ghost" href="<?= url('admin/stock/import', ['product_id' => (int)$w['id']]) ?>">补货</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3>最新订单</h3>
        <a class="btn btn-sm btn-ghost" href="<?= url('admin/order/list') ?>">查看全部 →</a>
    </div>
    <?php if (!$recent): ?>
        <div class="empty-line">暂无订单数据</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th>订单号</th><th>用户</th><th>商品</th><th>金额</th><th>状态</th><th>下单时间</th><th>操作</th></tr>
        </thead>
        <tbody>
            <?php foreach ($recent as $o): ?>
            <tr>
                <td class="mono"><?= e($o['order_no']) ?></td>
                <td><?= e($o['user_email'] ?? '-') ?></td>
                <td><?= e($o['product_name']) ?></td>
                <td class="text-price">¥<?= money($o['amount']) ?></td>
                <td><span class="status-badge status-<?= status_class((int)$o['status']) ?>"><?= status_text((int)$o['status']) ?></span></td>
                <td class="mono"><?= e($o['created_at']) ?></td>
                <td><a class="btn btn-sm btn-ghost" href="<?= url('admin/order/detail', ['id' => (int)$o['id']]) ?>">详情</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
