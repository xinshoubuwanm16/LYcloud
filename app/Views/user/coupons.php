<?php
/**
 * 我的优惠券（我的券包 + 领券中心）
 * @var array  $rows         我的券包
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var array  $claimCenter  领券中心
 * @var bool   $couponEnabled
 * @var bool   $claimEnabled
 * @var array  $stats
 */
$now = time();
$totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;

/** 计算券的展示状态：unused / used / expired */
$stateOf = function (array $uc) use ($now): string {
    $st = (int)$uc['status'];
    if ($st === \App\Models\UserCoupon::STATUS_USED) {
        return 'used';
    }
    if ($st === \App\Models\UserCoupon::STATUS_EXPIRED) {
        return 'expired';
    }
    if (!empty($uc['expires_at']) && strtotime((string)$uc['expires_at']) < $now) {
        return 'expired';
    }
    return 'unused';
};
$stateLabel = ['unused' => '可使用', 'used' => '已使用', 'expired' => '已失效'];
?>
<div class="wrap breadcrumb">
    <a href="<?= url('home/index') ?>">首页</a><span>/</span>
    <a href="<?= url('order/list') ?>">我的订单</a><span>/</span><em>我的优惠券</em>
</div>

<div class="wrap coupon-page">

    <?php /* 本页含 AJAX POST（领券），显式携带 CSRF 令牌，避免依赖其他表单 */ ?>
    <?= \App\Auth::csrfField() ?>

    <!-- 统计总览 -->
    <div class="coupon-summary">
        <div class="cs-item cs-main">
            <span>可使用</span>
            <b><?= (int)$stats['unused'] ?></b>
        </div>
        <div class="cs-item"><span>已使用</span><b><?= (int)$stats['used'] ?></b></div>
        <div class="cs-item"><span>已失效</span><b class="c-muted"><?= (int)$stats['expired'] ?></b></div>
        <div class="cs-item cs-saved">
            <span>累计已省</span><b class="c-price">¥<?= money($stats['saved']) ?></b>
        </div>
    </div>

    <?php if (!$couponEnabled): ?>
        <div class="flash flash-warning">优惠券功能当前已关闭，暂时无法领取或使用。</div>
    <?php endif; ?>

    <!-- 领券中心 -->
    <?php if ($couponEnabled && $claimEnabled): ?>
    <div class="coupon-card">
        <div class="coupon-card-head">
            <h2>领券中心</h2>
            <span class="coupon-card-note">
                <?= $claimCenter['total'] > 0 ? '共 ' . (int)$claimCenter['total'] . ' 张券可领取' : '暂无可领取的优惠券' ?>
            </span>
        </div>

        <?php if (empty($claimCenter['rows'])): ?>
            <div class="coupon-empty">
                <div class="ce-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4V8z"/></svg>
                </div>
                <p>当前没有可领取的优惠券</p>
                <span>请留意活动通知，或关注下单页的可用券提示</span>
            </div>
        <?php else: ?>
            <div class="coupon-grid">
                <?php foreach ($claimCenter['rows'] as $c):
                    $canClaim = (bool)$c['can_claim'];
                ?>
                <div class="coupon-ticket <?= $canClaim ? '' : 'is-disabled' ?>">
                    <div class="ct-left">
                        <div class="ct-amount">
                            <?php if ($c['type'] === \App\Models\Coupon::TYPE_DISCOUNT):
                                $rateNum = rtrim(rtrim(bcmul($c['value'], '10', 2), '0'), '.'); ?>
                                <span class="ct-num"><?= e($rateNum) ?></span><span class="ct-unit">折</span>
                            <?php else: ?>
                                <span class="ct-unit">¥</span><span class="ct-num"><?= money($c['value']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ct-cond"><?= bccomp($c['min_amount'], '0', 2) > 0 ? '满 ¥' . money($c['min_amount']) . ' 可用' : '无门槛' ?></div>
                    </div>
                    <div class="ct-right">
                        <div class="ct-name"><?= e($c['name']) ?></div>
                        <div class="ct-meta"><?= e(\App\Models\Coupon::describe($c)) ?></div>
                        <div class="ct-meta">
                            <?php if ((int)$c['scope'] === \App\Models\Coupon::SCOPE_ALL): ?>
                                <span class="badge badge-ok">全场通用</span>
                            <?php else: ?>
                                <span class="badge badge-info">指定商品</span>
                            <?php endif; ?>
                            <?php if ((int)$c['claimable'] === 1 && (int)$c['received_limit'] > 0): ?>
                                <span class="badge <?= (int)$c['remain'] > 0 ? 'badge-warn' : 'badge-muted' ?>">
                                    剩余 <?= (int)$c['remain'] ?> 张
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="ct-meta ct-expire">
                            <?php if (!empty($c['expires_at'])): ?>
                                有效期至 <?= e(substr((string)$c['expires_at'], 0, 16)) ?>
                            <?php else: ?>
                                <span class="c-muted">永久有效</span>
                            <?php endif; ?>
                            <?php if ((int)$c['per_user_limit'] > 0): ?>
                                · 每人限领 <?= (int)$c['per_user_limit'] ?> 张
                            <?php endif; ?>
                        </div>
                        <div class="ct-foot">
                            <?php if ($canClaim): ?>
                                <button class="btn btn-sm btn-primary js-claim" data-coupon-id="<?= (int)$c['id'] ?>">
                                    立即领取
                                </button>
                            <?php else: ?>
                                <span class="btn btn-sm btn-ghost disabled">
                                    <?= (int)$c['my_count'] > 0 ? '已领 ' . (int)$c['my_count'] . ' 张' : '已达上限' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ((int)$claimCenter['pages'] > 1): ?>
            <div class="coupon-pager">
                <?php if ((int)$claimCenter['page'] > 1): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= url('user/coupons', ['cpage' => (int)$claimCenter['page'] - 1]) ?>">上一页</a>
                <?php endif; ?>
                <span class="coupon-pager-info">第 <?= (int)$claimCenter['page'] ?> / <?= (int)$claimCenter['pages'] ?> 页</span>
                <?php if ((int)$claimCenter['page'] < (int)$claimCenter['pages']): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= url('user/coupons', ['cpage' => (int)$claimCenter['page'] + 1]) ?>">下一页</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 我的券包 -->
    <div class="coupon-card">
        <div class="coupon-card-head">
            <h2>我的券包</h2>
            <span class="coupon-card-note">共 <?= (int)$total ?> 张</span>
        </div>

        <?php if (empty($rows)): ?>
            <div class="coupon-empty">
                <div class="ce-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4V8z"/></svg>
                </div>
                <p>你还没有优惠券</p>
                <span><?= $claimEnabled ? '去上方「领券中心」领取，或等待管理员发放' : '等待管理员发放优惠券' ?></span>
            </div>
        <?php else: ?>
            <div class="coupon-grid">
                <?php foreach ($rows as $uc):
                    $state = $stateOf($uc);
                    $usable = $state === 'unused';
                ?>
                <div class="coupon-ticket coupon-mine is-<?= $state ?>">
                    <div class="ct-left">
                        <div class="ct-amount">
                            <?php if ($uc['type'] === \App\Models\Coupon::TYPE_DISCOUNT):
                                $rateNum = rtrim(rtrim(bcmul($uc['value'], '10', 2), '0'), '.'); ?>
                                <span class="ct-num"><?= e($rateNum) ?></span><span class="ct-unit">折</span>
                            <?php else: ?>
                                <span class="ct-unit">¥</span><span class="ct-num"><?= money($uc['value']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ct-cond"><?= bccomp($uc['min_amount'], '0', 2) > 0 ? '满 ¥' . money($uc['min_amount']) . ' 可用' : '无门槛' ?></div>
                    </div>
                    <div class="ct-right">
                        <div class="ct-name">
                            <?= e($uc['coupon_name'] ?: '（券已删除）') ?>
                            <span class="ct-state ct-state-<?= $state ?>"><?= e($stateLabel[$state]) ?></span>
                        </div>
                        <div class="ct-meta"><?= e(\App\Models\Coupon::describe($uc)) ?></div>
                        <div class="ct-meta">
                            <?php if ((int)$uc['scope'] === \App\Models\Coupon::SCOPE_ALL): ?>
                                <span class="badge badge-ok">全场通用</span>
                            <?php else: ?>
                                <span class="badge badge-info">指定商品</span>
                            <?php endif; ?>
                            <span class="badge badge-muted"><?= $uc['source'] === \App\Models\UserCoupon::SOURCE_CLAIM ? '自主领取' : '后台发放' ?></span>
                        </div>
                        <div class="ct-meta ct-expire">
                            <?php if (!empty($uc['expires_at'])): ?>
                                <?= $usable ? '有效期至 ' : '失效于 ' ?><?= e(substr((string)$uc['expires_at'], 0, 16)) ?>
                            <?php else: ?>
                                <span class="c-muted">永久有效</span>
                            <?php endif; ?>
                        </div>
                        <div class="ct-foot">
                            <?php if ($usable): ?>
                                <a class="btn btn-sm btn-primary" href="<?= url('product/list') ?>">去使用</a>
                            <?php elseif ($state === 'used' && (int)$uc['order_id'] > 0): ?>
                                <a class="btn btn-sm btn-ghost" href="<?= url('order/detail', ['id' => (int)$uc['order_id']]) ?>">查看订单</a>
                            <?php else: ?>
                                <span class="ct-usedat"><?= e($stateLabel[$state]) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="coupon-pager">
                <?php if ($page > 1): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= url('user/coupons', ['page' => $page - 1]) ?>">上一页</a>
                <?php else: ?>
                    <span class="btn btn-ghost btn-sm disabled">上一页</span>
                <?php endif; ?>
                <span class="coupon-pager-info">第 <?= (int)$page ?> / <?= $totalPages ?> 页</span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= url('user/coupons', ['page' => $page + 1]) ?>">下一页</a>
                <?php else: ?>
                    <span class="btn btn-ghost btn-sm disabled">下一页</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="bal-actions">
        <a class="btn btn-ghost" href="<?= url('user/balance') ?>">我的余额</a>
        <a class="btn btn-ghost" href="<?= url('order/list') ?>">我的订单</a>
        <a class="btn btn-ghost" href="<?= url('product/list') ?>">去购物</a>
    </div>
</div>

<?php if ($couponEnabled && $claimEnabled): ?>
<div class="coupon-toast" id="couponToast" hidden></div>
<script>
var CSRF = <?= json_encode(\App\Auth::csrfToken(), JSON_UNESCAPED_SLASHES) ?>;
(function () {
    var toast = document.getElementById('couponToast');
    var timer = null;

    function show(type, msg) {
        toast.hidden = false;
        toast.className = 'coupon-toast flash flash-' + type;
        toast.textContent = msg;
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(function () { toast.hidden = true; }, 3200);
    }

    document.querySelectorAll('.js-claim').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-coupon-id');
            if (!id || btn.disabled) { return; }

            btn.disabled = true;
            var original = btn.textContent;
            btn.textContent = '领取中…';

            var body = new URLSearchParams();
            body.append('_token', CSRF);
            body.append('coupon_id', id);

            fetch('<?= url('user/claimCoupon') ?>', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
            .then(function (res) {
                if (res.data && res.data.code === 0) {
                    show('success', res.data.msg);
                    setTimeout(function () { location.reload(); }, 1100);
                } else {
                    show('danger', (res.data && res.data.msg) || '领取失败，请稍后重试');
                    btn.disabled = false;
                    btn.textContent = original;
                }
            })
            .catch(function () {
                show('danger', '网络异常，请稍后重试');
                btn.disabled = false;
                btn.textContent = original;
            });
        });
    });
})();
</script>
<?php endif; ?>
