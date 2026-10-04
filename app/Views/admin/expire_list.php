<?php /** 后台到期管理（1.7.0） */
$tabs = [
    'due'      => '7 天内到期（' . (int)$stats['due'] . '）',
    'expired'  => '已到期待删机（' . (int)$stats['expired'] . '）',
    'disposed' => '已删机处理',
];
?>
<?php if ((int)$stats['expired'] > 0): ?>
<div class="flash flash-danger" style="margin-bottom:16px;">
    有 <b><?= (int)$stats['expired'] ?></b> 台机器已到期，请及时登录面板删除并回收资源。
    <a href="<?= url('admin/expire/list', ['kind' => 'expired']) ?>" style="color:inherit;font-weight:700;">前往处理 →</a>
</div>
<?php endif; ?>
<?php if ((int)$stats['due'] > 0): ?>
<div class="flash flash-warning" style="margin-bottom:16px;">
    有 <b><?= (int)$stats['due'] ?></b> 台机器将在 7 天内到期，系统已自动邮件提醒买家备份数据。
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3>到期管理 <small>商品有效期归集的待办面板</small></h3>
        <div class="head-actions">
            <?php foreach ($tabs as $k => $label): ?>
                <a class="btn btn-sm <?= $kind === $k ? 'btn-primary' : 'btn-ghost' ?>"
                   href="<?= url('admin/expire/list', ['kind' => $k]) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-line">
            <?= $kind === 'due' ? '近期没有即将到期的机器' : ($kind === 'expired' ? '没有已到期的机器，资源全部健康' : '还没有标记过删机记录') ?>
        </div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th style="width:170px">订单号</th>
                <th>买家</th>
                <th>商品</th>
                <th style="width:150px">到期时间</th>
                <th style="width:120px">状态</th>
                <th style="width:150px">提醒邮件</th>
                <th style="width:150px">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $o): ?>
            <tr>
                <td class="mono"><?= e($o['order_no']) ?></td>
                <td><?= e($o['user_email'] ?: '-') ?></td>
                <td>
                    <div class="cell-main"><?= e($o['product_name']) ?></div>
                    <?php if (!empty($o['panel_user'])): ?>
                        <div class="cell-sub mono">面板：<?= e($o['panel_user']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= e($o['expire_at']) ?></td>
                <td>
                    <?php if ($kind === 'disposed'): ?>
                        <span class="status-badge status-ok">已删机</span>
                        <div class="cell-sub mono"><?= e($o['disposed_at']) ?></div>
                    <?php elseif ((int)$o['days_left'] <= 0): ?>
                        <span class="status-badge status-danger">已到期 <?= -(int)$o['days_left'] ?> 天</span>
                    <?php else: ?>
                        <span class="status-badge status-warn">剩 <?= (int)$o['days_left'] ?> 天</span>
                    <?php endif; ?>
                </td>
                <td class="mono cell-sub"><?= !empty($o['reminded_at']) ? '已发 ' . e(substr((string)$o['reminded_at'], 5, 11)) : '—' ?></td>
                <td class="ops">
                    <?php if ($kind !== 'disposed'): ?>
                        <form method="post" action="<?= url('admin/expire/dispose') ?>" style="display:inline;">
                            <?= \App\Auth::csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="kind" value="<?= e($kind) ?>">
                            <button type="submit" class="btn btn-xs <?= $kind === 'expired' ? 'btn-danger' : 'btn-ghost' ?>"
                                    onclick="return confirm('确认已在面板删除该机器并回收资源？')">标记已删机</button>
                        </form>
                    <?php else: ?>
                        <span class="cell-sub">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
