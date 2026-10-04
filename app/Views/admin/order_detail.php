<?php /** 后台订单详情 */
$isMnbt  = !empty($isMnbt);
$dStatus = (int)($order['deliver_status'] ?? 0);
$mnbtDone   = $isMnbt && $dStatus === \App\Models\Order::DELIVER_DONE;
$mnbtFailed = $isMnbt && $dStatus === \App\Models\Order::DELIVER_FAILED;
$mnbtWait   = $isMnbt && in_array($dStatus, [\App\Models\Order::DELIVER_PENDING, \App\Models\Order::DELIVER_RETRY], true);
$dmText = \App\Models\Product::DELIVER_TEXT[
    $isMnbt ? \App\Models\Product::DELIVER_MNBT
            : (int)($product['deliver_mode'] ?? 1)
] ?? '—';
?>
<div class="card">
    <div class="card-head">
        <h3>订单详情 <small class="mono"><?= e($order['order_no']) ?></small></h3>
        <div class="head-actions">
            <span class="status-badge big status-<?= status_class((int)$order['status']) ?>"><?= status_text((int)$order['status']) ?></span>
            <a class="btn btn-sm btn-ghost" href="<?= url('admin/order/list') ?>">← 返回列表</a>
        </div>
    </div>

    <div class="grid-2col">
        <div>
            <h4 class="sub-title">订单信息</h4>
            <table class="kv-table">
                <tr><td>订单号</td><td class="mono"><?= e($order['order_no']) ?></td></tr>
                <tr><td>商品名称</td><td><?= e($order['product_name']) ?></td></tr>
                <tr><td>交付方式</td><td><?= e($dmText) ?></td></tr>
                <tr><td>订单金额</td><td class="text-price">¥<?= money($order['amount']) ?></td></tr>
                <tr><td>支付方式</td><td><?= e(pay_channel_text((string)$order['pay_channel'])) ?></td></tr>
                <tr><td>交易号</td><td class="mono"><?= e($order['trade_no'] ?: '—') ?></td></tr>
                <tr><td>下单 IP</td><td class="mono"><?= e($order['client_ip'] ?: '—') ?></td></tr>
                <tr><td>下单时间</td><td class="mono"><?= e($order['created_at']) ?></td></tr>
                <tr><td>支付时间</td><td class="mono"><?= e($order['paid_at'] ?: '—') ?></td></tr>
                <tr><td>发货时间</td><td class="mono"><?= e($order['delivered_at'] ?: '—') ?></td></tr>
            </table>
        </div>

        <div>
            <h4 class="sub-title">用户信息</h4>
            <table class="kv-table">
                <tr><td>用户 ID</td><td class="mono"><?= (int)$order['user_id'] ?></td></tr>
                <tr><td>邮箱</td><td><?= e($user['email'] ?? '—') ?></td></tr>
                <tr><td>昵称</td><td><?= e($user['nickname'] ?? '—') ?></td></tr>
                <tr><td>注册时间</td><td class="mono"><?= e($user['created_at'] ?? '—') ?></td></tr>
                <tr><td>最近登录 IP</td><td class="mono"><?= e($user['last_login_ip'] ?? '—') ?></td></tr>
            </table>
        </div>
    </div>
</div>

<?php if ($isMnbt): ?>
<div class="card" id="mnbt">
    <div class="card-head">
        <h3>主机自动开通</h3>
        <div class="head-actions">
            <?php
            $dBadge = [
                \App\Models\Order::DELIVER_NONE    => 'badge-info',
                \App\Models\Order::DELIVER_PENDING => 'badge-warn',
                \App\Models\Order::DELIVER_DONE    => 'badge-ok',
                \App\Models\Order::DELIVER_RETRY   => 'badge-warn',
                \App\Models\Order::DELIVER_FAILED  => 'badge-danger',
            ][$dStatus] ?? 'badge-info';
            ?>
            <span class="badge <?= $dBadge ?>"><?= e($deliverText) ?></span>
        </div>
    </div>

    <table class="kv-table">
        <tr><td>开通状态</td><td><?= e($deliverText) ?>
            <?php if ((int)($order['deliver_tries'] ?? 0) > 0): ?>
                <small class="text-muted">（已尝试 <?= (int)$order['deliver_tries'] ?> 次）</small>
            <?php endif; ?>
        </td></tr>
        <tr><td>主机账号</td><td class="mono"><?= e($order['mnbt_username'] ?: '—') ?></td></tr>
        <tr><td>主机规格</td><td><?= e($product ? \App\Models\Product::mnbtSpecText($product) : '—') ?>
            <small class="text-muted">（<?= e($product ? \App\Models\Product::mnbtTypeText($product) : '—') ?>）</small>
        </td></tr>
        <tr><td>到期时间</td><td class="mono"><?= e(\App\Models\Order::expireView($order['expire_at'] ?? null)) ?></td></tr>
        <?php if ((string)($order['deliver_error'] ?? '') !== ''): ?>
        <tr><td>最近失败原因</td><td class="text-danger"><?= e((string)$order['deliver_error']) ?></td></tr>
        <?php endif; ?>
        <tr><td>MNBT 接口</td>
            <td><?= $mnbtReady ? '<span class="badge badge-ok">已配置</span>' : '<span class="badge badge-danger">未配置</span>' ?></td>
        </tr>
    </table>

    <?php if ($mnbtFailed): ?>
        <div class="flash flash-danger" style="margin-top:14px">
            开通失败且重试已耗尽，款项已自动退回用户余额。若上游已恢复，请引导用户重新下单，
            避免重复扣款。
        </div>
    <?php elseif ($mnbtWait): ?>
        <div class="flash flash-warning" style="margin-top:14px">
            主机尚未开通完成<?= $dStatus === \App\Models\Order::DELIVER_RETRY ? '（等待自动重试）' : '' ?>。
            系统会按「系统设置 → MNBT对接」中配置的最大重试次数自动重试；
            也可在下方手动触发一次重试，确认上游已恢复。
        </div>
        <?php if (!$mnbtReady): ?>
            <div class="flash flash-danger" style="margin-top:10px">
                MNBT 接口未配置或不完整，自动开通无法进行。请前往
                <a href="<?= url('admin/setting', ['tab' => 'mnbt']) ?>">系统设置 → MNBT对接</a> 完善配置。
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <?php if ($mnbtWait): ?>
        <form method="post" action="<?= url('admin/order/retryMnbt') ?>" class="js-confirm"
              data-confirm="将立即向上游主机平台发起一次开通请求，确认继续？" style="display:inline">
            <?= \App\Auth::csrfField() ?>
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button class="btn btn-primary" type="submit">手动重试开通</button>
        </form>
        <?php endif; ?>
        <?php if ($mnbtDone): ?>
        <a class="btn btn-outline" href="<?= e(\App\Models\Order::mnbtLoginRelay((int)$order['id'])) ?>">代用户登录面板 →</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3>已分配的面板信息</h3>
    </div>

    <?php if ($panel): ?>
        <table class="kv-table">
            <tr><td>面板登录链接</td>
                <td><a class="mono" href="<?= e($panel['panel_url']) ?>" target="_blank" rel="noopener"><?= e($panel['panel_url']) ?></a></td>
            </tr>
            <tr><td>面板账号</td><td class="mono"><?= e($panel['panel_user']) ?></td></tr>
            <tr><td>面板密码</td><td class="mono"><?= e($panel['panel_pass']) ?></td></tr>
            <tr><td>备注</td><td><?= e($panel['remark'] ?: '—') ?></td></tr>
            <tr><td>库存记录 ID</td><td class="mono"><?= (int)$panel['id'] ?>
                <small class="text-muted">（来源：<?= (int)($panel['source'] ?? 1) === 2 ? 'MNBT 实时开通' : '预录导入' ?>）</small>
            </td></tr>
        </table>
    <?php else: ?>
        <div class="flash flash-warning">该订单尚未分配面板信息。</div>
    <?php endif; ?>
</div>

<?php if ((int)$order['status'] === 0): ?>
<div class="card">
    <div class="card-head"><h3>订单操作</h3></div>
    <?php if ((string)$order['pay_channel'] === 'donate'): ?>
        <?php /** 捐赠支付人工核验（1.7.9） */ ?>
        <div class="notice-box small" style="margin-bottom:14px">
            <h4>捐赠收款核验</h4>
            <?php if ((int)($order['user_claimed'] ?? 0) === 1): ?>
                <p><b style="color:#1d7a43">✓ 买家已声明完成付款</b>
                    <?php if (!empty($order['claim_at'])): ?>（<?= e($order['claim_at']) ?>）<?php endif; ?></p>
                <p>请核实您上传的收款码对应账单（金额 <b>¥<?= money($order['pay_amount']) ?></b>）：
                    确认到账后点击下方按钮，系统将按「已支付 → 自动发货」流程处理；未到账请直接关闭订单释放库存。</p>
            <?php else: ?>
                <p>该订单使用捐赠支付（无支付回调），买家尚未声明付款。可先核实收款账单，也可等买家在支付页点击「我已完成付款」后再处理。</p>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= url('admin/order/confirmDonate') ?>" class="js-confirm"
              data-confirm="确认已收到该订单的捐赠款项 ¥<?= money($order['pay_amount']) ?>？确认后将立即自动发货。">
            <?= \App\Auth::csrfField() ?>
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button class="btn btn-primary" type="submit">确认收款并发货</button>
        </form>
        <p class="text-muted" style="margin:8px 0 0">未到账？请使用下方「关闭订单并释放库存」。</p>
    <?php else: ?>
    <form method="post" action="<?= url('admin/order/close') ?>" class="js-confirm fatal-confirm"
          data-confirm="确定关闭该订单吗？占用的库存将被释放。">
        <?= \App\Auth::csrfField() ?>
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <p class="text-muted">该订单仍处于「待支付」状态。如用户已通过其他方式付款，请在下方手动发货；否则可关闭订单并释放库存。</p>
        <button class="btn btn-danger" type="submit" style="margin-top:12px">关闭订单并释放库存</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ((int)$order['status'] >= 1 && !$isMnbt): ?>
<div class="card">
    <div class="card-head">
        <h3><?= $panel ? '重新分配面板信息' : '手动发货' ?></h3>
    </div>

    <?php if (!$availStocks): ?>
        <div class="flash flash-warning">
            该商品当前没有可用的未售库存。请先前往
            <a href="<?= url('admin/stock/import', ['product_id' => (int)$order['product_id']]) ?>">批量导入库存</a>。
        </div>
    <?php else: ?>
    <form method="post" action="<?= url('admin/order/deliver') ?>">
        <?= \App\Auth::csrfField() ?>
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">

        <div class="form-row">
            <label>选择要分配的库存</label>
            <div class="form-control">
                <select name="stock_id" required>
                    <option value="">— 请选择一条未售库存 —</option>
                    <?php foreach ($availStocks as $s): ?>
                        <option value="<?= (int)$s['id'] ?>">
                            #<?= (int)$s['id'] ?> · <?= e($s['panel_url']) ?> · 账号 <?= e($s['panel_user']) ?>
                            <?= $s['remark'] !== '' ? ' · ' . e($s['remark']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>共 <?= count($availStocks) ?> 条可用库存</small>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">确认发货</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
