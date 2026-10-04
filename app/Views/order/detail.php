<?php /** 订单详情（含面板信息） */ 
$status = (int)$order['status'];
$delivered = $status >= 2;
$isMnbt = !empty($isMnbt);
$dStatus = (int)($order['deliver_status'] ?? 0);
// MNBT 订单的「已发货」由 deliver_status 决定，与 status 可能短暂不同步
$mnbtDone = $isMnbt && $dStatus === \App\Models\Order::DELIVER_DONE;
$mnbtWorking = $isMnbt && in_array($dStatus, [\App\Models\Order::DELIVER_PENDING, \App\Models\Order::DELIVER_RETRY], true);
$mnbtFailed = $isMnbt && $dStatus === \App\Models\Order::DELIVER_FAILED;
?>
<div class="wrap breadcrumb">
    <a href="<?= url('home/index') ?>">首页</a><span>/</span>
    <a href="<?= url('order/list') ?>">我的订单</a><span>/</span><em>订单详情</em>
</div>

<div class="wrap detail-page">
    <div class="detail-card">
        <div class="dc-head">
            <div>
                <h1><?= $delivered ? '面板信息已就绪' : '订单详情' ?></h1>
                <span class="order-no">订单号：<?= e($order['order_no']) ?></span>
            </div>
            <span class="status-badge big status-<?= status_class($status) ?>"><?= status_text($status) ?></span>
        </div>

        <div class="dc-progress">
            <div class="step <?= $status >= 0 ? 'done' : '' ?>">
                <i>1</i><span>提交订单</span>
            </div>
            <div class="step <?= $status >= 1 ? 'done' : '' ?>">
                <i>2</i><span>完成支付</span>
            </div>
            <div class="step <?= $status >= 2 ? 'done' : '' ?>">
                <i>3</i><span><?= $isMnbt ? '自动开通主机' : '自动发货' ?></span>
            </div>
            <div class="step <?= $status >= 2 ? 'done' : '' ?>">
                <i>4</i><span>获取面板</span>
            </div>
        </div>

        <?php if ($status === 0): ?>
            <?php $dueNow = \App\Models\BalanceLog::normalize($order['pay_amount'] ?? $order['amount']); ?>
            <div class="dc-pending">
                <p>订单尚未支付，请尽快完成付款以获取面板登录信息。</p>
                <a class="btn btn-primary btn-lg" href="<?= url('order/pay', ['id' => (int)$order['id']]) ?>">立即支付 ¥<?= money($dueNow) ?></a>
            </div>
        <?php endif; ?>

        <?php if ($isMnbt && $status >= 1): ?>
            <?php
            // MNBT 开通状态条：把「开通中 / 待重试 / 已开通 / 已退款」讲清楚，
            // 避免用户在上游抖动期间反复刷新或误以为被骗
            $barClass = $mnbtDone ? 'flash-success' : ($mnbtFailed ? 'flash-danger' : 'flash-warning');
            ?>
            <div class="flash <?= $barClass ?>" style="margin:16px 0 0">
                <?php if ($mnbtDone): ?>
                    <strong>主机已开通</strong>：账号资料已就绪，可在下方一键进入主机控制面板。
                <?php elseif ($mnbtFailed): ?>
                    <strong>主机开通失败，已退款</strong>：款项已自动退回您的账户余额。
                    原因：<?= e((string)($order['deliver_error'] ?: '上游返回异常')) ?>。
                    如需重新购买请
                    <a href="<?= url('product/show', ['id' => (int)$order['product_id']]) ?>">返回商品页</a>。
                <?php else: ?>
                    <strong>主机开通中</strong>：系统正在向上游主机平台提交开通请求，
                    通常 1 分钟内完成。<?php if ((int)($order['deliver_tries'] ?? 0) > 1): ?>(已尝试 <?= (int)$order['deliver_tries'] ?> 次，正在自动重试)<?php endif; ?>
                    请稍后刷新本页。
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($isMnbt && $panel): ?>
    <div class="detail-card panel-info-card" id="panel">
        <div class="pi-head">
            <h2>主机控制面板</h2>
            <span class="pi-note">请妥善保管，避免泄露</span>
        </div>

        <?php if ($mnbtLoginReady): ?>
        <div class="mnbt-oneclick">
            <a class="btn btn-primary btn-lg" href="<?= e(\App\Models\Order::mnbtLoginRelay((int)$order['id'])) ?>">
                一键登录主机面板 →
            </a>
            <span class="pi-tip">免输密码直接进入，链接为本站中转地址，不含您的密码</span>
        </div>
        <?php else: ?>
        <div class="flash flash-warning" style="margin-bottom:14px">
            主机仍在开通中，面板入口稍后可用，也可使用下方账号密码自行登录。
        </div>
        <?php endif; ?>

        <div class="pi-grid">
            <div class="pi-item full">
                <label>面板入口</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_url']) ?>" id="piUrl">
                    <button class="btn btn-copy" data-copy-target="piUrl">复制</button>
                    <a class="btn btn-outline" href="<?= e($panel['panel_url']) ?>" target="_blank" rel="noopener noreferrer">打开面板</a>
                </div>
            </div>

            <div class="pi-item">
                <label>主机账号</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_user']) ?>" id="piUser">
                    <button class="btn btn-copy" data-copy-target="piUser">复制</button>
                </div>
            </div>

            <div class="pi-item">
                <label>主机密码</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_pass']) ?>" id="piPass">
                    <button class="btn btn-copy" data-copy-target="piPass">复制</button>
                </div>
            </div>

            <div class="pi-item">
                <label>FTP 账号</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_user']) ?>" id="piFtp">
                    <button class="btn btn-copy" data-copy-target="piFtp">复制</button>
                </div>
            </div>

            <div class="pi-item">
                <label>FTP 密码</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_pass']) ?>" id="piFtpPass">
                    <button class="btn btn-copy" data-copy-target="piFtpPass">复制</button>
                </div>
            </div>

            <?php if (!empty($product) && $isMnbt): ?>
            <div class="pi-item full">
                <label>主机规格</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e(\App\Models\Product::mnbtSpecText($product)) ?>" id="piSpec">
                    <button class="btn btn-copy" data-copy-target="piSpec">复制</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="pi-actions">
            <button class="btn btn-primary" id="copyAll"
                    data-url="<?= e($panel['panel_url']) ?>"
                    data-user="<?= e($panel['panel_user']) ?>"
                    data-pass="<?= e($panel['panel_pass']) ?>">
                一键复制全部信息
            </button>
            <span class="pi-tip">复制后请及时登录面板并修改默认密码</span>
        </div>
    </div>
    <?php elseif ($isMnbt && $delivered): ?>
    <div class="detail-card">
        <div class="flash flash-warning">主机开通中，面板信息尚未生成，请稍后刷新页面查看。</div>
    </div>
    <?php elseif ($delivered && $panel): ?>
    <div class="detail-card panel-info-card" id="panel">
        <div class="pi-head">
            <h2>宝塔面板登录信息</h2>
            <span class="pi-note">请妥善保管，避免泄露</span>
        </div>

        <div class="pi-grid">
            <div class="pi-item full">
                <label>面板登录链接</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_url']) ?>" id="piUrl">
                    <button class="btn btn-copy" data-copy-target="piUrl">复制</button>
                    <a class="btn btn-outline" href="<?= e($panel['panel_url']) ?>" target="_blank" rel="noopener noreferrer">打开面板</a>
                </div>
            </div>

            <div class="pi-item">
                <label>面板账号</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_user']) ?>" id="piUser">
                    <button class="btn btn-copy" data-copy-target="piUser">复制</button>
                </div>
            </div>

            <div class="pi-item">
                <label>面板密码</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['panel_pass']) ?>" id="piPass">
                    <button class="btn btn-copy" data-copy-target="piPass">复制</button>
                </div>
            </div>

            <?php if (!empty($panel['remark'])): ?>
            <div class="pi-item full">
                <label>备注</label>
                <div class="pi-value">
                    <input type="text" readonly value="<?= e($panel['remark']) ?>" id="piRemark">
                    <button class="btn btn-copy" data-copy-target="piRemark">复制</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="pi-actions">
            <button class="btn btn-primary" id="copyAll"
                    data-url="<?= e($panel['panel_url']) ?>"
                    data-user="<?= e($panel['panel_user']) ?>"
                    data-pass="<?= e($panel['panel_pass']) ?>">
                一键复制全部信息
            </button>
            <span class="pi-tip">复制后请及时登录面板并修改默认密码</span>
        </div>
    </div>
    <?php elseif ($delivered): ?>
    <div class="detail-card">
        <div class="flash flash-warning">订单已支付，面板信息正在分配中，请稍后刷新页面查看。</div>
    </div>
    <?php endif; ?>

    <div class="detail-card">
        <h2>订单信息</h2>
        <table class="kv-table">
            <tr><td>订单号</td><td><?= e($order['order_no']) ?></td></tr>
            <tr><td>商品名称</td><td><?= e($order['product_name']) ?></td></tr>
            <tr><td>订单金额</td><td class="text-price">¥<?= money($order['amount']) ?></td></tr>
            <?php
            $balPaid = \App\Models\BalanceLog::normalize($order['balance_paid'] ?? '0.00');
            $hasBalance = bccomp($balPaid, '0', 2) > 0;
            $cpnPaid = \App\Models\BalanceLog::normalize($order['coupon_discount'] ?? '0.00');
            $hasCoupon = bccomp($cpnPaid, '0', 2) > 0;
            ?>
            <?php if ($hasCoupon): ?>
            <tr>
                <td>优惠券抵扣</td>
                <td class="txt-out">- ¥<?= money($cpnPaid) ?>
                    <?php if ((int)($order['coupon_id'] ?? 0) > 0):
                        $oc = \App\Models\Coupon::find((int)$order['coupon_id']);
                        if ($oc): ?>
                        <span class="badge badge-info"><?= e($oc['name']) ?></span>
                    <?php endif; endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php if ($hasBalance): ?>
            <tr>
                <td>余额抵扣</td>
                <td class="txt-out">- ¥<?= money($balPaid) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($hasCoupon || $hasBalance): ?>
            <tr>
                <td>实付金额</td>
                <td class="text-price">¥<?= money($order['pay_amount'] ?? $order['amount']) ?></td>
            </tr>
            <?php endif; ?>
            <tr><td>支付方式</td><td><?= pay_channel_text((string)$order['pay_channel']) ?></td></tr>
            <tr><td>交易号</td><td><?= e($order['trade_no'] ?: '—') ?></td></tr>
            <tr><td>订单状态</td><td><?= status_text($status) ?></td></tr>
            <tr><td>下单时间</td><td><?= e($order['created_at']) ?></td></tr>
            <tr><td>支付时间</td><td><?= e($order['paid_at'] ?: '—') ?></td></tr>
            <?php if (in_array($status, [1, 2], true)): ?>
            <tr>
                <td>有效期</td>
                <td class="<?= !empty($order['expire_at']) && strtotime($order['expire_at']) < time() ? 'text-danger' : '' ?>">
                    <?= e(\App\Models\Order::expireView($order['expire_at'] ?? null)) ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>

        <div class="detail-actions">
            <a class="btn btn-ghost" href="<?= url('order/list') ?>">返回订单列表</a>
            <a class="btn btn-ghost" href="<?= url('product/list') ?>">继续购买</a>
        </div>
    </div>

    <div class="detail-card help-card">
        <h2>使用帮助</h2>
        <?php if ($isMnbt): ?>
        <ol>
            <li>主机由上游主机平台自动开通，支付成功后通常 1 分钟内完成，本页会自动更新状态。</li>
            <li>点击上方「<strong>一键登录主机面板</strong>」可直接进入控制面板，无需手动输入账号密码。</li>
            <li>也可复制「面板入口」在浏览器打开，再用主机账号 / 密码登录；FTP 上传请使用 FTP 账号 / 密码。</li>
            <li>主机到期时间与订单有效期一致，到期前请及时续费，避免站点被暂停。</li>
            <li>若状态长时间停留在「开通中」，请稍后刷新；如显示「开通失败」，款项会自动退回您的账户余额。</li>
        </ol>
        <?php else: ?>
        <ol>
            <li>复制上方的「面板登录链接」，在浏览器中打开（若为 IPv6 地址，请确保本机已开启 IPv6）。</li>
            <li>输入面板账号与密码登录宝塔面板。</li>
            <li>首次登录后建议立即修改面板密码，并绑定宝塔账号以便后续找回。</li>
            <li>如遇面板无法访问，请先确认网络环境支持 IPv6，或联系客服协助排查。</li>
        </ol>
        <?php endif; ?>
    </div>
</div>

<?php if ($mnbtWorking && $status >= 1): ?>
<?php /* 主机开通中：轻量轮询订单状态，开通完成后自动刷新本页，
       免去用户反复手动刷新（上游开通通常数秒内完成，故 5s 一次）。*/ ?>
<script>
(function () {
    var tries = 0, MAX = 24; // 最多轮询 2 分钟，之后停止避免空转
    var timer = setInterval(function () {
        if (++tries > MAX) { clearInterval(timer); return; }
        if (document.hidden) { return; } // 页面在后台时不请求，省流量
        fetch('<?= url('order/queryStatus', ['id' => (int)$order['id']]) ?>', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && (d.delivered || (d.status || 0) >= 2)) {
                    clearInterval(timer);
                    location.reload();
                }
            })
            .catch(function () { /* 网络抖动忽略，等待下次轮询 */ });
    }, 5000);
})();
</script>
<?php endif; ?>
