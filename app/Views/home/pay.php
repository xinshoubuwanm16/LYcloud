<?php
/** 收银台：当面付二维码 / 电脑网站支付 / 易支付（API 取码）/ 模拟支付 */
$isQr        = $channel === 'qr';
$isYipay     = \App\Payment\YiPayGateway::isChannel((string)$channel);
$isDonate    = $channel === 'donate';
$yipayType   = $isYipay ? \App\Payment\YiPayGateway::typeOfChannel((string)$channel) : '';
$yipayQrType = ['alipay' => '支付宝', 'wxpay' => '微信', 'qqpay' => 'QQ 钱包'][$yipayType] ?? '';
// 通道切换列表：捐赠扫码 + 官方两模式 + 已启用易支付子通道
$switches = [];
if (\App\Models\Setting::donateReady()) {
    $switches[] = ['donate', '捐赠扫码'];
}
if (!empty($alipayReady)) {
    $switches[] = ['qr', '当面付扫码'];
    $switches[] = ['page', '电脑网站支付'];
}
foreach ((array)($yipayChannels ?? []) as $yt) {
    $switches[] = [\App\Payment\YiPayGateway::makeChannel($yt), '易支付·' . (\App\Payment\YiPayGateway::CHANNEL_LABELS[$yt] ?? $yt)];
}
?>
<div class="wrap breadcrumb">
    <a href="<?= url('home/index') ?>">首页</a><span>/</span>
    <a href="<?= url('order/list') ?>">我的订单</a><span>/</span><em>订单支付</em>
</div>

<div class="wrap pay-page">
    <div class="pay-card">
        <div class="pay-head">
            <h1>订单支付</h1>
            <span class="order-no">订单号：<?= e($order['order_no']) ?></span>
        </div>

        <div class="pay-amount">
            <span class="label">应付金额</span>
            <span class="value"><i>¥</i><?= money($order['amount']) ?></span>
        </div>

        <div class="pay-info">
            <div><span>商品名称</span><b><?= e($order['product_name']) ?></b></div>
            <div><span>支付方式</span>
                <b><?= $isDonate ? '捐赠支付（扫码·人工核验）' : ($isYipay ? '易支付 - ' . e($yipayQrType) : ($isQr ? '支付宝当面付（扫码）' : '支付宝电脑网站支付')) ?></b>
            </div>
            <div><span>订单状态</span>
                <b class="status-<?= status_class((int)$order['status']) ?>"><?= status_text((int)$order['status']) ?></b>
            </div>
        </div>

        <?php if (count($switches) > 1): ?>
        <div class="pay-channel-switch">
            <?php foreach ($switches as [$c, $label]): ?>
                <a class="<?= $c === $channel ? 'on' : '' ?>" href="<?= url('order/pay', ['id' => (int)$order['id'], 'channel' => $c]) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="flash flash-danger" style="margin:18px 0"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($isQr || $isYipay): ?>
            <div class="qr-area">
                <?php if ($qrCode): ?>
                    <div class="qr-box" id="qrBox" data-content="<?= e($qrCode) ?>">
                        <canvas id="qrCanvas" width="220" height="220"></canvas>
                    </div>
                    <p class="qr-tip">请使用「<?= $isYipay ? e($yipayQrType) : '支付宝' ?> App」扫描上方二维码完成付款</p>
                    <p class="qr-tip-m">手机付款：长按或截图保存上方二维码，打开<?= $isYipay ? e($yipayQrType) : '支付宝' ?>「扫一扫」从相册识别付款；若在微信内打开本页，请点右上角「···」用浏览器打开后支付。</p>
                    <?php if ($isYipay && $payJump): ?>
                    <p style="margin-top:8px">
                        <a class="btn btn-outline" href="<?= e($payJump) ?>" target="_blank" rel="noopener">无法扫码？前往支付页面 →</a>
                    </p>
                    <?php endif; ?>
                    <p class="qr-status" id="qrStatus">
                        <span class="spin"></span> 等待支付结果...
                    </p>
                <?php elseif (!$error): ?>
                    <p class="qr-tip">正在生成支付二维码...</p>
                <?php else: ?>
                    <div class="qr-placeholder">二维码暂不可用</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="page-pay-tip">
                <p>点击下方按钮将跳转到支付宝收银台完成支付。</p>
                <?php if ($configured): ?>
                    <a class="btn btn-primary btn-lg" href="<?= e(url('order/pay', ['id' => (int)$order['id'], 'channel' => 'page'])) ?>">前往支付宝付款</a>
                <?php else: ?>
                    <p class="text-muted">支付宝接口未配置，无法跳转收银台。</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isDonate): ?>
            <?php /** 捐赠支付（1.7.9）：展示收款码 + 用户声明付款 + 管理员人工核验 */ ?>
            <div class="qr-area donate-pay-area">
                <?php if (trim((string)($donateDesc ?? '')) !== ''): ?>
                <p class="dm-desc" style="margin-bottom:14px"><?= nl2br(e($donateDesc)) ?></p>
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
                <p class="qr-tip">请使用支付宝 / 微信「扫一扫」识别上方收款码，按订单金额 <b>¥<?= money($order['amount']) ?></b> 付款</p>
                <p class="qr-tip-m">手机付款：长按或截图保存收款码，打开对应 App「扫一扫」从相册识别付款。</p>

                <?php if ((int)($order['user_claimed'] ?? 0) === 1): ?>
                <div class="donate-claimed">
                    <b>✓ 已提交付款声明</b>
                    <?php if (!empty($order['claim_at'])): ?><span><?= e($order['claim_at']) ?></span><?php endif; ?>
                    <p>管理员核实到账后将自动发货，请留意订单状态与邮件通知；如长时间未核验，可通过站内联系方式提醒管理员。</p>
                </div>
                <?php else: ?>
                <form method="post" action="<?= url('order/claimDonate') ?>" style="margin-top:14px">
                    <?= \App\Auth::csrfField() ?>
                    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                    <button class="btn btn-primary btn-lg" type="submit">我已完成付款</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($demoEnabled && !$isDonate): ?>
        <div class="demo-pay-box">
            <div class="demo-title">演示模式</div>
            <p>当前为演示环境（无外部网络回调），可点击下方按钮模拟「支付成功」，用于完整验证
               <strong>下单 → 支付 → 自动发货 → 查看面板信息</strong> 全流程。</p>
            <a class="btn btn-warn" href="<?= url('pay/demo', ['out_trade_no' => $order['order_no']]) ?>">
                模拟支付成功（仅演示用）
            </a>
        </div>
        <?php endif; ?>

        <div class="pay-footer">
            <a href="<?= url('order/detail', ['id' => (int)$order['id']]) ?>">查看订单详情</a>
            <a href="<?= url('order/list') ?>">返回我的订单</a>
        </div>
    </div>
</div>

<?php if (($isQr || $isYipay) && $qrCode): ?>
<script src="<?= asset('assets/js/qrcode.min.js') ?>"></script>
<script>
(function () {
    var box = document.getElementById('qrBox');
    if (box && window.QRCodeLib) {
        QRCodeLib.render(document.getElementById('qrCanvas'), box.dataset.content);
    }
    var statusEl = document.getElementById('qrStatus');
    var orderId  = <?= (int)$order['id'] ?>;
    var timer = setInterval(function () {
        fetch('<?= url('order/queryStatus', ['id' => (int)$order['id']]) ?>', {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.status >= 1) {
                    clearInterval(timer);
                    statusEl.innerHTML = '<span class="ok-dot"></span> 支付成功，正在跳转...';
                    setTimeout(function () {
                        location.href = '<?= url('order/detail', ['id' => (int)$order['id']]) ?>';
                    }, 800);
                }
            })
            .catch(function () {});
    }, 3000);

    // 30 分钟超时提示
    setTimeout(function () {
        clearInterval(timer);
        statusEl.innerHTML = '二维码已过期，请刷新页面重新获取';
    }, 30 * 60 * 1000);
})();
</script>
<?php endif; ?>
