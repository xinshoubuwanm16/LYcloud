<?php
/** 商品详情 */
$tags = array_filter(array_map('trim', explode(',', $product['tags'])));
$available = (int)$product['stock_mode'] === 1 ? $stockCount : 9999;
$stockOut = (int)$product['stock_mode'] === 1 && $available <= 0;
$cat       = $category ?? null;
$catName   = \App\Models\Category::nameOf($cat);
$catStyle  = $cat ? \App\Models\Category::colorStyle($cat) : '';

// 每人限购：未登录时只看限购额度（不查个人已购数），登录后给出剩余可购次数
$limitPerUser = \App\Models\Product::normalizeLimit((int)($product['limit_per_user'] ?? 0));
$currentUser  = \App\Auth::user();
$limitBought  = 0;
$limitLeft    = null;
if ($limitPerUser > 0 && $currentUser) {
    $limitBought = \App\Models\Product::purchasedCount((int)$currentUser['id'], (int)$product['id']);
    $limitLeft   = max(0, $limitPerUser - $limitBought);
}
$limitReached = $limitPerUser > 0 && $currentUser && $limitLeft !== null && $limitLeft <= 0;

$soldOut = $stockOut || $limitReached;

// 可用支付通道：官方支付宝（已配置时）+ 易支付子通道（已启用时）
$alipayOn = \App\Models\Setting::alipayConfigured();
$yipayChannels = \App\Models\Setting::yipayEnabled() ? \App\Models\Setting::yipayChannels() : [];
$yipayDesc = [
    'alipay' => ['易支付 - 支付宝', '通过易支付跳转支付宝付款'],
    'wxpay'  => ['易支付 - 微信支付', '通过易支付跳转微信扫码付款'],
    'qqpay'  => ['易支付 - QQ 钱包', '通过易支付跳转 QQ 钱包付款'],
];
?>

<div class="wrap breadcrumb">
    <a href="<?= url('home/index') ?>">首页</a>
    <span>/</span>
    <a href="<?= url('product/list') ?>">全部商品</a>
    <?php if ($cat): ?>
    <span>/</span>
    <a href="<?= url('product/list', ['category' => (int)$cat['id']]) ?>"<?= $catStyle !== '' ? ' style="' . e($catStyle) . '"' : '' ?>><?= e($catName) ?></a>
    <?php endif; ?>
    <span>/</span>
    <em><?= e($product['name']) ?></em>
</div>

<div class="wrap product-detail">
    <div class="pd-main">
        <div class="pd-card">
            <div class="pd-head">
                <h1><?= e($product['name']) ?></h1>
                <?php if ($product['subtitle'] !== ''): ?>
                    <p class="pd-sub"><?= e($product['subtitle']) ?></p>
                <?php endif; ?>
                <?php if ($cat || $tags): ?>
                <div class="product-tags">
                    <?php if ($cat): ?>
                    <a class="tag tag-cat"<?= $catStyle !== '' ? ' style="' . e($catStyle) . '"' : '' ?>
                       href="<?= url('product/list', ['category' => (int)$cat['id']]) ?>"><?= e($catName) ?></a>
                    <?php endif; ?>
                    <?php foreach ($tags as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="pd-price-row">
                <div class="pd-price">
                    <span class="cur">¥</span><span class="num"><?= money($product['price']) ?></span>
                    <?php if ($product['original_price'] > $product['price']): ?>
                        <span class="origin">¥<?= money($product['original_price']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="pd-price-side">
                    <span>已售 <?= number_format((int)$product['sales']) ?> 台</span>
                    <span>库存
                        <b class="<?= $stockOut ? 'text-danger' : 'text-ok' ?>">
                            <?= (int)$product['stock_mode'] === 1 ? $available . ' 台' : '不限量' ?>
                        </b>
                    </span>
                    <?php if ($limitPerUser > 0): ?>
                    <span>限购
                        <b class="<?= $limitReached ? 'text-danger' : 'text-ok' ?>">
                            <?php if ($limitLeft === null): ?>
                                每人 <?= $limitPerUser ?> 次
                            <?php elseif ($limitReached): ?>
                                已购满 <?= $limitPerUser ?> 次
                            <?php else: ?>
                                还可购 <?= $limitLeft ?> 次
                            <?php endif; ?>
                        </b>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <a class="btn btn-primary pd-quick-buy" href="#buyBox"><?= $limitReached ? '已达限购上限' : ($stockOut ? '查看库存状态' : '立即购买') ?></a>

            <div class="pd-deliver-note">
                <span class="badge-auto">自动发货</span>
                支付成功后系统立即分配「宝塔面板登录链接 + 账号 + 密码」，可在「我的订单」中随时查看。
                <span style="display:block;margin-top:6px;">有效期：<b><?= e(\App\Models\Product::durationText($product)) ?></b><?= ($product['duration_value'] ?? 0) > 0 ? '（自支付时间起算，到期前 7 天邮件提醒）' : '' ?></span>
                <?php if ($limitPerUser > 0): ?>
                <span style="display:block;margin-top:6px;">购买限制：<b>每人限购 <?= $limitPerUser ?> 次</b>（按已付款订单计，待支付/已关闭订单不占名额）<?php if ($limitLeft !== null && !$limitReached): ?>，您还可购买 <b class="text-ok"><?= $limitLeft ?></b> 次<?php endif; ?></span>
                <?php endif; ?>
            </div>

            <div class="pd-section">
                <h3>套餐详情</h3>
                <div class="pd-desc">
                    <?php if (trim((string)$product['description']) !== ''): ?>
                        <?= nl2br(e($product['description'])) ?>
                    <?php else: ?>
                        <p><?= e($product['spec'] ?: 'IPv6 宝塔面板主机，配置详情请咨询客服。') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pd-section">
                <h3>交付内容</h3>
                <table class="kv-table">
                    <tr><td>面板登录链接</td><td>宝塔面板完整访问地址（含端口与安全入口）</td></tr>
                    <tr><td>面板账号</td><td>宝塔面板登录用户名</td></tr>
                    <tr><td>面板密码</td><td>宝塔面板登录密码</td></tr>
                    <tr><td>交付方式</td><td>支付成功后订单页自动展示，支持一键复制</td></tr>
                </table>
            </div>

            <div class="pd-section">
                <h3>购买须知</h3>
                <div class="pd-desc">
                    <?= nl2br(e($__site['site_notice'] ?? '本站商品均为虚拟服务类商品，一经发货不支持退款，请确认需求后再下单。')) ?>
                </div>
            </div>
        </div>
    </div>

    <aside class="pd-aside" id="buyBox">
        <div class="buy-box">
            <?php $isFree = bccomp(money($product['price']), '0', 2) === 0; // 1.7.10：0 元商品免费领取 ?>
            <div class="buy-price">
                <?php if ($isFree): ?>
                    <span class="num" style="font-size:30px;letter-spacing:0;">免费领取</span>
                <?php else: ?>
                    <span class="cur">¥</span><span class="num"><?= money($product['price']) ?></span>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= url('order/create') ?>">
                <?= \App\Auth::csrfField() ?>
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

                <?php if (!$isFree): // 0 元商品无需选择支付方式 ?>
                <div class="pay-method-title">选择支付方式</div>
                <div class="pay-methods">
                    <?php $first = true; ?>
                    <?php if (!empty($donateReady)): ?>
                    <label class="pay-method">
                        <input type="radio" name="pay_channel" value="donate" <?= $first ? 'checked' : '' ?> data-donate-radio>
                        <span class="pm-box">
                            <b>捐赠支付（扫码）</b>
                            <em>支付宝 / 微信收款码付款，人工核验后自动发货</em>
                        </span>
                    </label>
                        <?php $first = false; ?>
                    <?php endif; ?>
                    <?php if ($alipayOn): ?>
                    <label class="pay-method">
                        <input type="radio" name="pay_channel" value="qr" <?= $first ? 'checked' : '' ?>>
                        <span class="pm-box">
                            <b>当面付（扫码）</b>
                            <em>打开支付宝扫一扫，即时到账</em>
                        </span>
                    </label>
                    <label class="pay-method">
                        <input type="radio" name="pay_channel" value="page">
                        <span class="pm-box">
                            <b>电脑网站支付</b>
                            <em>跳转支付宝收银台完成付款</em>
                        </span>
                    </label>
                        <?php $first = false; ?>
                    <?php endif; ?>
                    <?php foreach ($yipayChannels as $yType): ?>
                    <label class="pay-method">
                        <input type="radio" name="pay_channel" value="yipay_<?= e($yType) ?>" <?= $first ? 'checked' : '' ?>>
                        <span class="pm-box">
                            <b><?= e($yipayDesc[$yType][0] ?? '易支付 - ' . $yType) ?></b>
                            <em><?= e($yipayDesc[$yType][1] ?? '通过易支付付款') ?></em>
                        </span>
                    </label>
                        <?php $first = false; ?>
                    <?php endforeach; ?>
                    <?php if (!$alipayOn && !$yipayChannels && empty($donateReady)): ?>
                        <?php // 官方、易支付、捐赠均未就绪：保留原有两项，支付页会给出未配置提示（演示模式可用） ?>
                    <label class="pay-method">
                        <input type="radio" name="pay_channel" value="qr" checked>
                        <span class="pm-box">
                            <b>当面付（扫码）</b>
                            <em>打开支付宝扫一扫，即时到账</em>
                        </span>
                    </label>
                    <label class="pay-method">
                        <input type="radio" name="pay_channel" value="page">
                        <span class="pm-box">
                            <b>电脑网站支付</b>
                            <em>跳转支付宝收银台完成付款</em>
                        </span>
                    </label>
                    <?php endif; ?>
                </div>
                <?php endif; // !$isFree ?>

                <?php if ($__auth_user): ?>
                    <?php
                    $userBalance = \App\Models\BalanceLog::normalize($__auth_user['balance'] ?? '0.00');
                    $canUseBalance = \App\Models\Setting::balanceEnabled() && bccomp($userBalance, '0', 2) > 0;
                    $fullyCovered = $canUseBalance && bccomp($userBalance, $product['price'], 2) >= 0;
                    $couponOn = \App\Models\Setting::couponEnabled();
                    ?>
                    <?php if ($couponOn && !$isFree): // 0 元商品无可折金额，不出示优惠券 ?>
                    <div class="coupon-deduct" id="couponBox">
                        <label class="cd-label" for="couponSelect">优惠券</label>
                        <select name="user_coupon_id" id="couponSelect" data-product-id="<?= (int)$product['id'] ?>">
                            <option value="">加载中…</option>
                        </select>
                        <div class="cd-note" id="couponNote">
                            正在查询可用优惠券…<a href="<?= url('user/coupons') ?>">去领券中心 →</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($canUseBalance && !$isFree): ?>
                    <div class="balance-deduct">
                        <label class="bd-check">
                            <input type="checkbox" name="use_balance" value="1" id="useBalance">
                            <span class="bd-box">
                                <b>使用余额抵扣</b>
                                <em>
                                    当前余额 ¥<?= money($userBalance) ?>
                                    <?php if ($fullyCovered): ?>
                                        · 可全额抵扣，下单后立即发货
                                    <?php else: ?>
                                        · 抵扣后需支付 ¥<?= money(bcsub($product['price'], $userBalance, 2)) ?>
                                    <?php endif; ?>
                                </em>
                            </span>
                        </label>
                        <div class="bd-note">
                            <a href="<?= url('user/balance') ?>">充值余额 →</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-block btn-lg" <?= $soldOut ? 'disabled' : '' ?>>
                        <?= $limitReached ? '已达购买上限' : ($stockOut ? '暂时缺货' : ($isFree ? '免费领取' : '立即购买')) ?>
                    </button>
                    <?php if ($limitReached): ?>
                        <p class="cd-note" style="margin-top:10px;text-align:center;">
                            该商品每人限购 <?= $limitPerUser ?> 次，您已购买 <?= $limitBought ?> 次。如有特殊需求请联系客服。
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="btn btn-primary btn-block btn-lg" href="<?= url('auth/login') ?>">登录后购买</a>
                    <a class="btn btn-outline btn-block" href="<?= url('auth/register') ?>" style="margin-top:10px">没有账号？立即注册</a>
                <?php endif; ?>
            </form>

            <div class="buy-guarantee">
                <div><?= $isFree ? '0 元商品，免支付直接发货' : '官方支付宝 / 易支付多通道' ?></div>
                <div>支付成功后自动发货</div>
                <div>订单信息永久可查</div>
            </div>
        </div>

        <?php if ($others): ?>
        <div class="other-products">
            <h4>其他套餐</h4>
            <?php foreach ($others as $o): ?>
                <a class="op-item" href="<?= url('product/show', ['id' => (int)$o['id']]) ?>">
                    <span class="op-name"><?= e($o['name']) ?></span>
                    <span class="op-price">¥<?= money($o['price']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </aside>
</div>

<?php if (!empty($__auth_user) && \App\Models\Setting::couponEnabled()): ?>
<script>
(function () {
    var sel  = document.getElementById('couponSelect');
    var note = document.getElementById('couponNote');
    if (!sel) return;

    var productId = sel.getAttribute('data-product-id');
    var balanceEl = document.getElementById('useBalance');
    var price     = <?= json_encode(money($product['price'])) ?>;

    var allCoupons = [];

    function node(sel) { return document.querySelector(sel); }

    // 拉取可用券
    function load() {
        var token = node('#couponBox')
            ? (document.querySelector('form [name=_token]') || {}).value
            : '';
        if (!token) { return; }
        var body = new URLSearchParams();
        body.append('_token', token);
        body.append('product_id', productId);
        body.append('amount', price);

        fetch('<?= url('user/couponQuote') ?>', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || d.code !== 0 || !d.list) { render([]); return; }
            allCoupons = d.list;
            render(d.list);
        })
        .catch(function () { render([]); });
    }

    function render(list) {
        sel.innerHTML = '';
        var optNone = document.createElement('option');
        optNone.value = '';
        optNone.textContent = list.length
            ? '不使用优惠券（' + list.length + ' 张可用）'
            : '暂无可用优惠券';
        sel.appendChild(optNone);

        list.forEach(function (c) {
            var o = document.createElement('option');
            o.value = c.user_coupon_id;
            o.textContent = c.name + '（' + c.describe + '，立减 ¥' + c.discount + '）';
            o.setAttribute('data-discount', c.discount);
            sel.appendChild(o);
        });

        note.innerHTML = list.length
            ? '共 <b>' + list.length + '</b> 张可用，结算时自动按「先券后余额」抵扣。'
              + ' <a href="<?= url('user/coupons') ?>">去领更多 →</a>'
            : '暂无可用优惠券。 <a href="<?= url('user/coupons') ?>">去领券中心 →</a>';
    }

    sel.addEventListener('change', updateBalanceTip);
    if (balanceEl) { balanceEl.addEventListener('change', updateBalanceTip); }

    // 券与余额叠加时，实时提示抵扣后还需支付多少
    function updateBalanceTip() {
        var tip = document.querySelector('#useBalance + .bd-box em');
        if (!tip || !balanceEl || !balanceEl.checked) { return; }
        var opt   = sel.options[sel.selectedIndex];
        var disc  = opt ? parseFloat(opt.getAttribute('data-discount') || '0') : 0;
        var after = Math.max(0, parseFloat(price) - disc);
        var bal   = parseFloat((tip.getAttribute('data-balance') || '0'));
        var pay   = Math.max(0, after - bal);
        tip.innerHTML = '当前余额 ¥' + bal.toFixed(2)
            + (disc > 0 ? '，叠加优惠券后应付 ¥' + after.toFixed(2) : '')
            + (pay <= 0 ? ' · 可全额抵扣，下单后立即发货' : ' · 抵扣后需支付 ¥' + pay.toFixed(2));
    }

    // 记录余额原文，便于叠加提示重新计算
    (function () {
        var tip = document.querySelector('#useBalance + .bd-box em');
        if (!tip) { return; }
        var m = tip.textContent.match(/¥([\d.]+)/);
        if (m) { tip.setAttribute('data-balance', m[1]); }
    })();

    load();
})();
</script>
<?php endif; ?>

<?php if (!empty($donateReady)): ?>
<?php /** 捐赠支付收款码预览弹窗（1.7.9）：点击「捐赠支付」方式时弹出 */ ?>
<div class="donate-modal" id="donateModal" hidden aria-modal="true" role="dialog" aria-label="捐赠支付收款码">
    <div class="dm-mask" data-donate-close></div>
    <div class="dm-box">
        <button class="dm-close" type="button" data-donate-close aria-label="关闭">&times;</button>
        <h3>扫码付款</h3>
        <?php if (trim((string)($donateDesc ?? '')) !== ''): ?>
        <p class="dm-desc"><?= nl2br(e($donateDesc)) ?></p>
        <?php endif; ?>
        <div class="dm-qr-grid">
            <?php if (!empty($donateQrAli)): ?>
            <figure class="dm-qr">
                <img src="<?= e(base_url($donateQrAli)) ?>" alt="支付宝收款码">
                <figcaption>支付宝</figcaption>
            </figure>
            <?php endif; ?>
            <?php if (!empty($donateQrWechat)): ?>
            <figure class="dm-qr">
                <img src="<?= e(base_url($donateQrWechat)) ?>" alt="微信收款码">
                <figcaption>微信</figcaption>
            </figure>
            <?php endif; ?>
        </div>
        <p class="dm-note">点击「立即购买」创建订单后，在支付页<strong>长按或截图扫码</strong>付款，付款后点击「我已完成付款」，管理员核实到账后即自动发货。</p>
        <button class="btn btn-primary" type="button" data-donate-close>我知道了，继续下单</button>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('donateModal');
    if (!modal) { return; }
    document.querySelectorAll('[data-donate-radio]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (radio.checked) { modal.hidden = false; }
        });
    });
    modal.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-donate-close]')) { modal.hidden = true; }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) { modal.hidden = true; }
    });
})();
</script>
<?php endif; ?>
