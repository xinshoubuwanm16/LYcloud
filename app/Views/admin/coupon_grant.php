<?php
/** 后台定向发放优惠券 */
$perLimit = max(1, (int)$coupon['per_user_limit']);
$expired = !empty($coupon['expires_at']) && strtotime((string)$coupon['expires_at']) < time();
?>
<div class="card">
    <div class="card-head">
        <h3>发放优惠券 <small><?= e($coupon['name']) ?></small></h3>
        <div class="head-actions">
            <a class="btn btn-sm btn-ghost" href="<?= url('admin/coupon/list') ?>">返回列表</a>
            <a class="btn btn-sm btn-ghost" href="<?= url('admin/coupon/holders', ['id' => (int)$coupon['id']]) ?>">查看持券人</a>
        </div>
    </div>

    <div class="stat-row">
        <div class="stat-box"><span>优惠规则</span><b style="font-size:13.5px"><?= e(\App\Models\Coupon::describe($coupon)) ?></b></div>
        <div class="stat-box"><span>类型</span><b style="font-size:13.5px"><?= e(\App\Models\Coupon::typeLabel($coupon)) ?></b></div>
        <div class="stat-box"><span>每人限次</span><b><?= $perLimit ?> 次</b></div>
        <div class="stat-box">
            <span>适用范围</span>
            <b style="font-size:13.5px">
                <?= (int)$coupon['scope'] === \App\Models\Coupon::SCOPE_ALL ? '全场通用' : '指定商品' ?>
            </b>
        </div>
        <div class="stat-box">
            <span>状态</span>
            <b style="font-size:13.5px">
                <?php if ((int)$coupon['status'] === \App\Models\Coupon::STATUS_ON && !$expired): ?>
                    <span class="c-ok">启用中</span>
                <?php else: ?>
                    <span class="c-muted"><?= $expired ? '已过期' : '已停用' ?></span>
                <?php endif; ?>
            </b>
        </div>
    </div>

    <?php if ((int)$coupon['status'] !== \App\Models\Coupon::STATUS_ON || $expired): ?>
        <div class="flash flash-warning">
            该券当前<?= $expired ? '已过期' : '已停用' ?>，发放后用户也无法在结算时使用，建议先到编辑页调整为启用状态。
        </div>
    <?php endif; ?>

    <form method="post" action="<?= url('admin/coupon/doGrant') ?>" class="form-horizontal">
        <?= \App\Auth::csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$coupon['id'] ?>">

        <div class="form-row">
            <label>按邮箱批量发放</label>
            <div class="form-control">
                <textarea name="emails" rows="3"
                          placeholder="多个邮箱用逗号或换行分隔，例如：&#10;user1@qq.com, user2@qq.com"></textarea>
                <small>
                    只识别系统中<b>已注册</b>的邮箱，未注册的会被忽略。
                    发放时会自动应用「每人限 <?= $perLimit ?> 次」规则，已达上限的用户会被跳过。
                </small>
            </div>
        </div>

        <div class="form-row">
            <label>勾选用户发放</label>
            <div class="form-control">
                <?php if (!$userOptions): ?>
                    <div class="empty-line">暂无注册用户</div>
                <?php else: ?>
                    <div class="pick-toolbar">
                        <input type="text" id="userFilter" placeholder="输入邮箱或昵称筛选…"
                               oninput="filterUsers(this.value)">
                        <span class="c-muted" id="pickCount">已选 0 人</span>
                    </div>
                    <div class="pick-list pick-list-scroll" id="userPickList">
                        <?php foreach ($userOptions as $u): ?>
                        <label class="pick-item" data-key="<?= e(strtolower($u['email'] . ' ' . $u['nickname'])) ?>">
                            <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" onchange="countPick()">
                            <span>
                                <?= e($u['email']) ?>
                                <em>ID <?= (int)$u['id'] ?><?= $u['nickname'] !== '' ? ' · ' . e($u['nickname']) : '' ?></em>
                                <?php if ((int)$u['status'] !== 1): ?><i class="badge badge-muted">已禁用</i><?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small>
                        上方列表最多展示最近 500 位用户。单次最多发放给
                        <b><?= (int)$maxGrant ?></b> 人，两种方式可叠加使用。
                    </small>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">确认发放</button>
            <a class="btn btn-ghost" href="<?= url('admin/coupon/list') ?>">取消</a>
        </div>
    </form>
</div>

<script>
function countPick() {
    var n = document.querySelectorAll('#userPickList input[type=checkbox]:checked').length;
    var el = document.getElementById('pickCount');
    if (el) el.textContent = '已选 ' + n + ' 人';
}
function filterUsers(kw) {
    kw = (kw || '').toLowerCase().trim();
    document.querySelectorAll('#userPickList .pick-item').forEach(function (item) {
        var key = item.getAttribute('data-key') || '';
        item.style.display = (kw === '' || key.indexOf(kw) !== -1) ? '' : 'none';
    });
}
countPick();
</script>
