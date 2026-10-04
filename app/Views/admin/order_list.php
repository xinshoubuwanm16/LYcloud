<?php /** 后台订单列表 */ 
$statusOptions = ['' => '全部状态', 0 => '待支付', 1 => '已支付', 2 => '已发货', 3 => '已关闭', 4 => '已退款'];
?>
<div class="card">
    <div class="card-head">
        <h3>订单管理 <small>共 <?= (int)$total ?> 笔</small></h3>
        <div class="head-actions">
            <form class="search-form" method="get" action="<?= url('admin/order/list') ?>">
                <input type="hidden" name="r" value="admin/order/list">
                <select name="status">
                    <?php foreach ($statusOptions as $k => $label): ?>
                        <option value="<?= e((string)$k) ?>" <?= (string)$filter['status'] === (string)$k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="keyword" value="<?= e($filter['keyword']) ?>" placeholder="订单号/邮箱/交易号">
                <button class="btn btn-sm btn-ghost" type="submit">筛选</button>
            </form>
            <a class="btn btn-outline btn-sm"
               href="<?= url('admin/order/export', ['status' => $filter['status'], 'keyword' => $filter['keyword']]) ?>">导出 CSV</a>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-line">暂无订单</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th style="width:180px">订单号</th>
                <th>用户</th>
                <th>商品</th>
                <th style="width:100px">金额</th>
                <th style="width:100px">支付方式</th>
                <th style="width:90px">状态</th>
                <th style="width:120px">开通状态</th>
                <th style="width:160px">下单时间</th>
                <th style="width:150px">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $o): ?>
            <?php
            $dStatus = (int)($o['deliver_status'] ?? 0);
            // 仅 MNBT 订单（有账号快照或状态非 0）才展示开通状态列，避免噪声
            $showDeliver = $dStatus > 0 || (string)($o['mnbt_username'] ?? '') !== '';
            $dBadge = [
                1 => ['badge-warn', '开通中'],
                2 => ['badge-ok',   '已开通'],
                3 => ['badge-warn', '待重试'],
                4 => ['badge-danger', '开通失败'],
            ][$dStatus] ?? null;
            ?>
            <tr>
                <td class="mono"><?= e($o['order_no']) ?></td>
                <td>
                    <div class="cell-main"><?= e($o['user_email'] ?? '-') ?></div>
                    <?php if (!empty($o['user_nickname'])): ?>
                        <div class="cell-sub"><?= e($o['user_nickname']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($o['product_name']) ?></td>
                <td class="text-price">¥<?= money($o['amount']) ?></td>
                <td>
                    <?= e(pay_channel_text_short((string)$o['pay_channel'])) ?>
                    <?php if ((string)$o['pay_channel'] === 'donate' && (int)($o['user_claimed'] ?? 0) === 1 && (int)$o['status'] === 0): ?>
                        <span class="badge badge-warn" title="买家已声明付款，待人工核验">已声明付款</span>
                    <?php endif; ?>
                </td>
                <td><span class="status-badge status-<?= status_class((int)$o['status']) ?>"><?= status_text((int)$o['status']) ?></span></td>
                <td>
                    <?php if ($dBadge): ?>
                        <span class="badge <?= $dBadge[0] ?>"><?= $dBadge[1] ?></span>
                        <?php if ((int)($o['deliver_tries'] ?? 0) > 1): ?>
                            <small class="text-muted">×<?= (int)$o['deliver_tries'] ?></small>
                        <?php endif; ?>
                    <?php elseif ($showDeliver): ?>
                        <small class="text-muted">—</small>
                    <?php else: ?>
                        <small class="text-muted">—</small>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= e($o['created_at']) ?></td>
                <td class="ops">
                    <a class="btn btn-xs btn-ghost" href="<?= url('admin/order/detail', ['id' => (int)$o['id']]) ?>">详情</a>
                    <?php if (in_array($dStatus, [1, 3], true)): ?>
                        <a class="btn btn-xs btn-outline" href="<?= url('admin/order/detail', ['id' => (int)$o['id']]) ?>#mnbt"
                           title="待重试订单，进入详情可手动重试">重试</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?= paginate((int)$total, (int)$perPage, (int)$page, 'admin/order/list', [
        'status' => $filter['status'],
        'keyword' => $filter['keyword'],
    ]) ?>
    <?php endif; ?>
</div>
