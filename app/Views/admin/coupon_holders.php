<?php
/** 后台某张券的持券人明细 */
$statusMap = [
    \App\Models\UserCoupon::STATUS_UNUSED  => ['未使用', 'badge-ok'],
    \App\Models\UserCoupon::STATUS_USED    => ['已使用', 'badge-info'],
    \App\Models\UserCoupon::STATUS_EXPIRED => ['已失效', 'badge-muted'],
];
$sourceMap = [
    \App\Models\UserCoupon::SOURCE_ADMIN => '后台发放',
    \App\Models\UserCoupon::SOURCE_CLAIM => '自主领取',
];
?>
<div class="card">
    <div class="card-head">
        <h3>持券人明细 <small><?= e($coupon['name']) ?> · 共 <?= (int)$total ?> 张</small></h3>
        <div class="head-actions">
            <a class="btn btn-sm btn-ghost" href="<?= url('admin/coupon/list') ?>">返回列表</a>
            <a class="btn btn-sm btn-primary" href="<?= url('admin/coupon/grant', ['id' => (int)$coupon['id']]) ?>">继续发放</a>
        </div>
    </div>

    <div class="stat-row">
        <div class="stat-box"><span>优惠规则</span><b style="font-size:14px"><?= e(\App\Models\Coupon::describe($coupon)) ?></b></div>
        <div class="stat-box"><span>已发放</span><b><?= (int)$coupon['received_count'] ?></b></div>
        <div class="stat-box"><span>已核销</span><b class="c-info"><?= (int)$coupon['used_count'] ?></b></div>
        <div class="stat-box"><span>每人限次</span><b><?= (int)$coupon['per_user_limit'] ?> 次</b></div>
        <div class="stat-box">
            <span>发放总量</span>
            <b><?= (int)$coupon['received_limit'] > 0 ? (int)$coupon['received_limit'] : '不限' ?></b>
        </div>
    </div>

    <!-- 状态筛选 -->
    <form class="search-form filter-form" method="get" action="<?= url('admin/coupon/holders') ?>">
        <input type="hidden" name="r" value="admin/coupon/holders">
        <input type="hidden" name="id" value="<?= (int)$coupon['id'] ?>">
        <select name="status">
            <option value="">全部状态</option>
            <option value="0"<?= (string)$status === '0' ? ' selected' : '' ?>>未使用</option>
            <option value="1"<?= (string)$status === '1' ? ' selected' : '' ?>>已使用</option>
            <option value="2"<?= (string)$status === '2' ? ' selected' : '' ?>>已失效</option>
        </select>
        <button class="btn btn-sm btn-ghost" type="submit">筛选</button>
        <a class="btn btn-sm btn-ghost" href="<?= url('admin/coupon/holders', ['id' => (int)$coupon['id']]) ?>">重置</a>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-line">暂无持券记录<?= $status !== '' && $status !== null ? '（当前筛选条件下）' : '，点击右上角「继续发放」开始' ?></div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th style="width:56px">ID</th>
                <th>用户</th>
                <th style="width:100px">来源</th>
                <th style="width:90px">状态</th>
                <th style="width:170px">使用订单</th>
                <th style="width:160px">领取时间</th>
                <th style="width:160px">使用 / 失效</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $uc):
                $st = (int)$uc['status'];
                $s  = $statusMap[$st] ?? ['未知', 'badge-muted'];
                $isExpiredUnused = $st === \App\Models\UserCoupon::STATUS_UNUSED
                    && !empty($uc['expires_at'])
                    && strtotime((string)$uc['expires_at']) < time();
            ?>
            <tr>
                <td class="mono"><?= (int)$uc['id'] ?></td>
                <td>
                    <b><?= e($uc['email'] ?? '（用户已删除）') ?></b>
                    <?php if (!empty($uc['nickname'])): ?>
                        <div class="cell-sub"><?= e($uc['nickname']) ?> · ID <?= (int)$uc['user_id'] ?></div>
                    <?php else: ?>
                        <div class="cell-sub">ID <?= (int)$uc['user_id'] ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge <?= $uc['source'] === \App\Models\UserCoupon::SOURCE_CLAIM ? 'badge-info' : 'badge-ok' ?>">
                        <?= e($sourceMap[$uc['source']] ?? $uc['source']) ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= $s[1] ?>"><?= $s[0] ?></span>
                    <?php if ($isExpiredUnused): ?>
                        <span class="badge badge-warn">已过期</span>
                    <?php endif; ?>
                </td>
                <td class="mono">
                    <?php if ((int)$uc['order_id'] > 0): ?>
                        <?= e($uc['order_no'] ?: '#' . (int)$uc['order_id']) ?>
                    <?php else: ?>
                        <span class="c-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= e($uc['created_at'] ?? '—') ?></td>
                <td class="mono">
                    <?php if ($uc['used_at']): ?>
                        已用 <?= e($uc['used_at']) ?>
                    <?php elseif (!empty($uc['expires_at'])): ?>
                        <?= e($uc['expires_at']) ?>
                    <?php else: ?>
                        <span class="c-muted">永久有效</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?= paginate((int)$total, (int)$perPage, (int)$page, 'admin/coupon/holders', [
        'id'     => (int)$coupon['id'],
        'status' => $status,
    ]) ?>
    <?php endif; ?>
</div>
