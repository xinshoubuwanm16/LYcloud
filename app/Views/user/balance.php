<?php
/**
 * 我的余额
 * @var string $balance
 * @var array  $logs
 * @var int    $logTotal
 * @var int    $page
 * @var array  $balanceStats
 * @var bool   $redeemEnabled
 */
$typeMap = [
    'redeem'  => ['兑换码充值', 'in'],
    'admin'   => ['管理员调整', 'in'],
    'consume' => ['余额消费',   'out'],
    'refund'  => ['订单退还',   'in'],
];
$totalPages = $perPage > 0 ? (int)ceil($logTotal / $perPage) : 1;
?>
<div class="wrap breadcrumb">
    <a href="<?= url('home/index') ?>">首页</a><span>/</span>
    <a href="<?= url('order/list') ?>">我的订单</a><span>/</span><em>我的余额</em>
</div>

<div class="wrap balance-page">

    <!-- 余额总览 -->
    <div class="bal-hero">
        <div class="bal-hero-main">
            <span class="bal-label">账户可用余额</span>
            <div class="bal-amount"><i>¥</i><?= money($balance) ?></div>
            <p class="bal-tip">余额可用于下单时抵扣订单金额，全额抵扣时无需支付宝、即时自动发货。</p>
        </div>
        <div class="bal-hero-stats">
            <div class="bh-stat">
                <span>累计充值</span>
                <b>¥<?= money($balanceStats['recharged_total']) ?></b>
            </div>
            <div class="bh-stat">
                <span>累计消费</span>
                <b>¥<?= money($balanceStats['consumed_total']) ?></b>
            </div>
            <div class="bh-stat">
                <span>累计退还</span>
                <b>¥<?= money($balanceStats['refunded_total']) ?></b>
            </div>
        </div>
    </div>

    <!-- 兑换码 -->
    <div class="bal-card">
        <div class="bal-card-head">
            <h2>兑换码充值</h2>
            <span class="bal-card-note">输入兑换码即可将面额充入账户余额</span>
        </div>

        <?php if ($redeemEnabled): ?>
            <form class="redeem-form" id="redeemForm" autocomplete="off">
                <input type="hidden" name="_token" value="<?= e($__csrf) ?>">
                <input type="text" name="code" id="redeemCode" class="redeem-input"
                       placeholder="请输入兑换码，如 LY7K9M2X4TP9Q8"
                       maxlength="20" spellcheck="false" autocapitalize="characters">
                <button type="submit" class="btn btn-primary" id="redeemBtn">立即兑换</button>
            </form>
            <div class="redeem-result" id="redeemResult" hidden></div>
            <p class="redeem-help">
                兑换码不区分大小写，共 14 位（以 <b>LY</b> 开头）。
                兑换成功后余额立即到账，可在下方明细中查看记录。
            </p>
        <?php else: ?>
            <div class="flash flash-warning">兑换功能当前已关闭，如需充值请联系客服。</div>
        <?php endif; ?>
    </div>

    <!-- 余额明细 -->
    <div class="bal-card">
        <div class="bal-card-head">
            <h2>余额明细</h2>
            <span class="bal-card-note">共 <?= (int)$logTotal ?> 条记录</span>
        </div>

        <?php if (empty($logs)): ?>
            <div class="bal-empty">
                <div class="bal-empty-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8v11a2 2 0 01-2 2H5a2 2 0 01-2-2V8M2 8h20l-2-4H4L2 8zM10 12h4"/></svg>
                </div>
                <p>暂无余额变动记录</p>
                <span>使用兑换码充值后，记录会出现在这里</span>
            </div>
        <?php else: ?>
            <div class="bal-table-wrap">
                <table class="bal-table">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>类型</th>
                            <th>说明</th>
                            <th class="ta-r">变动金额</th>
                            <th class="ta-r">变动后余额</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log):
                        $t = $typeMap[$log['type']] ?? [$log['type'], 'in'];
                        $isIn = $t[1] === 'in';
                    ?>
                        <tr>
                            <td class="bal-time"><?= e($log['created_at']) ?></td>
                            <td>
                                <span class="bal-tag bal-tag-<?= $isIn ? 'in' : 'out' ?>">
                                    <?= e($t[0]) ?>
                                </span>
                            </td>
                            <td class="bal-remark"><?= e($log['remark'] ?: '—') ?></td>
                            <td class="ta-r <?= $isIn ? 'txt-in' : 'txt-out' ?>">
                                <?= $isIn ? '+' : '' ?><?= money($log['change']) ?>
                            </td>
                            <td class="ta-r bal-after">¥<?= money($log['after']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="bal-pager">
                <?php if ($page > 1): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= url('user/balance', ['page' => $page - 1]) ?>">上一页</a>
                <?php else: ?>
                    <span class="btn btn-ghost btn-sm disabled">上一页</span>
                <?php endif; ?>

                <span class="bal-pager-info">第 <?= (int)$page ?> / <?= $totalPages ?> 页</span>

                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= url('user/balance', ['page' => $page + 1]) ?>">下一页</a>
                <?php else: ?>
                    <span class="btn btn-ghost btn-sm disabled">下一页</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="bal-actions">
        <a class="btn btn-ghost" href="<?= url('order/list') ?>">我的订单</a>
        <a class="btn btn-ghost" href="<?= url('product/list') ?>">去购物</a>
    </div>
</div>

<?php if ($redeemEnabled): ?>
<script>
(function () {
    var form   = document.getElementById('redeemForm');
    if (!form) return;
    var input  = document.getElementById('redeemCode');
    var btn    = document.getElementById('redeemBtn');
    var result = document.getElementById('redeemResult');

    function show(type, msg) {
        result.hidden = false;
        result.className = 'redeem-result flash flash-' + type;
        result.textContent = msg;
    }

    function refreshBalance(newBalance) {
        var el = document.querySelector('.bal-amount');
        if (el && newBalance) { el.innerHTML = '<i>¥</i>' + newBalance; }
        // 同步顶栏余额徽标
        document.querySelectorAll('[data-balance-slot]').forEach(function (n) {
            n.textContent = '¥' + newBalance;
        });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var code = (input.value || '').trim().toUpperCase();
        if (!code) { show('warning', '请输入兑换码'); return; }

        btn.disabled = true;
        btn.textContent = '兑换中...';

        var body = new URLSearchParams();
        body.append('_token', form.querySelector('[name=_token]').value);
        body.append('code', code);

        fetch('<?= url('user/redeem') ?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
            if (res.data && res.data.code === 0) {
                show('success', res.data.msg + '（当前余额 ¥' + res.data.balance + '）');
                input.value = '';
                refreshBalance(res.data.balance);
                setTimeout(function () { location.reload(); }, 1400);
            } else {
                show('danger', (res.data && res.data.msg) || '兑换失败，请稍后重试');
            }
        })
        .catch(function () { show('danger', '网络异常，请稍后重试'); })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = '立即兑换';
        });
    });

    // 输入自动转大写
    input.addEventListener('input', function () {
        var p = input.selectionStart;
        input.value = input.value.toUpperCase();
        input.setSelectionRange(p, p);
    });
})();
</script>
<?php endif; ?>
