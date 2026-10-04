<?php
/** 后台优惠券列表 */
$typeMap = [
    'reduce'   => ['满减券', 'badge-ok'],
    'discount' => ['折扣券', 'badge-info'],
];
$now = time();
?>
<div class="card">
    <div class="card-head">
        <h3>优惠券管理 <small>共 <?= (int)$total ?> 张</small></h3>
        <div class="head-actions">
            <a class="btn btn-sm btn-primary" href="<?= url('admin/coupon/form') ?>">新增优惠券</a>
        </div>
    </div>

    <!-- 统计 -->
    <div class="stat-row">
        <div class="stat-box"><span>券总数</span><b><?= (int)$stats['total'] ?></b></div>
        <div class="stat-box"><span>启用中</span><b class="c-ok"><?= (int)$stats['active'] ?></b></div>
        <div class="stat-box"><span>可自主领取</span><b class="c-info"><?= (int)$stats['claimable'] ?></b></div>
        <div class="stat-box"><span>已发放未用</span><b><?= (int)$stats['held'] ?></b></div>
        <div class="stat-box"><span>已核销</span><b class="c-info"><?= (int)$stats['used'] ?></b></div>
        <div class="stat-box"><span>累计优惠</span><b class="c-price">¥<?= money($stats['discounted']) ?></b></div>
    </div>

    <!-- 筛选 -->
    <form class="search-form filter-form" method="get" action="<?= url('admin/coupon/list') ?>">
        <input type="hidden" name="r" value="admin/coupon/list">
        <input type="text" name="keyword" value="<?= e($keyword) ?>" placeholder="券名 / 券码 / 备注">
        <select name="type">
            <option value="">全部类型</option>
            <option value="reduce"<?= $type === 'reduce' ? ' selected' : '' ?>>满减券</option>
            <option value="discount"<?= $type === 'discount' ? ' selected' : '' ?>>折扣券</option>
        </select>
        <select name="scope">
            <option value="">全部范围</option>
            <option value="0"<?= (string)$scope === '0' ? ' selected' : '' ?>>全场通用</option>
            <option value="1"<?= (string)$scope === '1' ? ' selected' : '' ?>>指定商品</option>
        </select>
        <select name="status">
            <option value="">全部状态</option>
            <option value="1"<?= (string)$status === '1' ? ' selected' : '' ?>>启用</option>
            <option value="0"<?= (string)$status === '0' ? ' selected' : '' ?>>停用</option>
        </select>
        <button class="btn btn-sm btn-ghost" type="submit">筛选</button>
        <a class="btn btn-sm btn-ghost" href="<?= url('admin/coupon/list') ?>">重置</a>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-line">暂无优惠券，点击右上角「新增优惠券」开始创建</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th style="width:56px">ID</th>
                <th>名称 / 规则</th>
                <th style="width:110px">类型</th>
                <th style="width:150px">适用范围</th>
                <th style="width:100px">每人限次</th>
                <th style="width:120px">发放 / 核销</th>
                <th style="width:150px">有效期</th>
                <th style="width:90px">状态</th>
                <th style="width:210px">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c):
                $t = $typeMap[$c['type']] ?? ['未知', 'badge-muted'];
                $expired = !empty($c['expires_at']) && strtotime((string)$c['expires_at']) < $now;
                $notStarted = !empty($c['start_at']) && strtotime((string)$c['start_at']) > $now;
            ?>
            <tr>
                <td class="mono"><?= (int)$c['id'] ?></td>
                <td>
                    <b><?= e($c['name']) ?></b>
                    <div class="cell-sub"><?= e(\App\Models\Coupon::describe($c)) ?></div>
                    <?php if (!empty($c['code']) && strpos((string)$c['code'], '__') !== 0): ?>
                        <div class="cell-sub">券码：<span class="mono"><?= e($c['code']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($c['remark'])): ?>
                        <div class="cell-sub"><?= e($c['remark']) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $t[1] ?>"><?= $t[0] ?></span></td>
                <td>
                    <?php if ((int)$c['scope'] === \App\Models\Coupon::SCOPE_ALL): ?>
                        <span class="badge badge-ok">全场通用</span>
                    <?php else: ?>
                        <span class="badge badge-info">指定商品</span>
                        <div class="cell-sub">
                            <?= $c['scope_names']
                                ? e(implode('、', array_slice($c['scope_names'], 0, 2)))
                                  . (count($c['scope_names']) > 2 ? ' 等 ' . count($c['scope_names']) . ' 个' : '')
                                : '<span class="c-muted">—</span>' ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= (int)$c['per_user_limit'] ?> 次</td>
                <td>
                    <span class="badge badge-ok"><?= (int)$c['received_count'] ?></span>
                    / <span class="badge badge-info"><?= (int)$c['used_count'] ?></span>
                    <div class="cell-sub">
                        <?php if ((int)$c['received_limit'] > 0): ?>
                            限量 <?= (int)$c['received_limit'] ?>
                        <?php else: ?>
                            <span class="c-muted">不限量</span>
                        <?php endif; ?>
                        <?php if ((int)$c['claimable'] === 1): ?>
                            · <span class="c-ok">可自领</span>
                        <?php endif; ?>
                    </div>
                    <?php $uu = $usage[(int)$c['id']] ?? ['available' => 0, 'held' => 0, 'used' => 0]; ?>
                    <?php if ((int)$uu['available'] > 0): ?>
                        <div class="cell-sub">
                            <span class="c-warn">未使用 <?= (int)$uu['available'] ?> 张</span>
                            <?php if ((int)$uu['used'] > 0): ?>· 订单引用 <?= (int)$uu['used'] ?> 笔<?php endif; ?>
                        </div>
                    <?php elseif ((int)$uu['held'] > 0 || (int)$uu['used'] > 0): ?>
                        <div class="cell-sub c-muted">券已全部使用/失效，可安全删除</div>
                    <?php endif; ?>
                </td>
                <td class="mono">
                    <?php if (empty($c['start_at']) && empty($c['expires_at'])): ?>
                        <span class="c-muted">永久有效</span>
                    <?php else: ?>
                        <?= $c['start_at'] ? e($c['start_at']) : '<span class="c-muted">即刻</span>' ?>
                        <br>~ <?= $c['expires_at'] ? e($c['expires_at']) : '<span class="c-muted">永久</span>' ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$c['status'] === \App\Models\Coupon::STATUS_ON): ?>
                        <span class="badge badge-ok">启用</span>
                    <?php else: ?>
                        <span class="badge badge-muted">停用</span>
                    <?php endif; ?>
                    <?php if ($expired): ?><span class="badge badge-warn">已过期</span><?php endif; ?>
                    <?php if ($notStarted): ?><span class="badge badge-warn">未开始</span><?php endif; ?>
                </td>
                <td class="ops">
                    <a class="btn btn-xs btn-ghost" href="<?= url('admin/coupon/form', ['id' => (int)$c['id']]) ?>">编辑</a>
                    <a class="btn btn-xs btn-ghost" href="<?= url('admin/coupon/grant', ['id' => (int)$c['id']]) ?>">发放</a>
                    <a class="btn btn-xs btn-ghost" href="<?= url('admin/coupon/holders', ['id' => (int)$c['id']]) ?>">持券人</a>
                    <form method="post" action="<?= url('admin/coupon/toggle') ?>" class="inline">
                        <?= \App\Auth::csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="page" value="<?= (int)$page ?>">
                        <input type="hidden" name="keyword" value="<?= e($keyword) ?>">
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <input type="hidden" name="status" value="<?= e((string)$status) ?>">
                        <input type="hidden" name="scope" value="<?= e((string)$scope) ?>">
                        <button class="btn btn-xs <?= (int)$c['status'] === 1 ? 'btn-ghost' : 'btn-primary' ?>" type="submit">
                            <?= (int)$c['status'] === 1 ? '停用' : '启用' ?>
                        </button>
                    </form>
                    <form method="post" action="<?= url('admin/coupon/delete') ?>" class="inline js-confirm"
                          data-confirm="确定删除「<?= e($c['name']) ?>」吗？删除后不可恢复。">
                        <?= \App\Auth::csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="page" value="<?= (int)$page ?>">
                        <input type="hidden" name="keyword" value="<?= e($keyword) ?>">
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <input type="hidden" name="status" value="<?= e((string)$status) ?>">
                        <input type="hidden" name="scope" value="<?= e((string)$scope) ?>">
                        <?php if ((int)($usage[(int)$c['id']]['available'] ?? 0) > 0): ?>
                            <?php /* 券还在用户手中：走强制删除，需输入口令二次确认 */ ?>
                            <input type="hidden" name="force" value="1">
                            <input type="text" name="confirm" class="confirm-word" required
                                   placeholder="输入：<?= e(\App\Models\Coupon::FORCE_CONFIRM_WORD) ?>"
                                   aria-label="强制删除确认口令">
                            <button class="btn btn-xs btn-danger" type="submit"
                                    data-confirm="⚠️ 强制删除会【收回用户手中 %n 张未使用的券】，且不可恢复。确认继续？"
                                    data-confirm-n="<?= (int)$usage[(int)$c['id']]['available'] ?>">强制删除</button>
                        <?php else: ?>
                            <button class="btn btn-xs btn-danger" type="submit">删除</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?= paginate((int)$total, (int)$perPage, (int)$page, 'admin/coupon/list', [
        'keyword' => $keyword,
        'type'    => $type,
        'status'  => $status,
        'scope'   => $scope,
    ]) ?>
    <?php endif; ?>
</div>
