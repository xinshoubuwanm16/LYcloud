<?php
/** 后台优惠券新增 / 编辑表单 */
$isEdit = $coupon !== null;
$v = function (string $key, string $default = '') use ($coupon) {
    // 优先回填（校验失败返回），其次库值，最后默认
    $old = old($key, null);
    if ($old !== null && $old !== '') {
        return (string)$old;
    }
    return $coupon !== null ? (string)$coupon[$key] : $default;
};
$curScope  = (string)$v('scope', (string)\App\Models\Coupon::SCOPE_ALL);
$curType   = $v('type', 'reduce');
$curStatus = (string)$v('status', (string)\App\Models\Coupon::STATUS_ON);
$claimable = $v('claimable', '0') === '1';
?>
<div class="card">
    <div class="card-head">
        <h3><?= $isEdit ? '编辑优惠券' : '新增优惠券' ?></h3>
        <div class="head-actions">
            <a class="btn btn-sm btn-ghost" href="<?= url('admin/coupon/list') ?>">返回列表</a>
        </div>
    </div>

    <form method="post" action="<?= url('admin/coupon/save') ?>" class="form-horizontal">
        <?= \App\Auth::csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="form-row">
            <label>优惠券名称 <em>*</em></label>
            <div class="form-control">
                <input type="text" name="name" maxlength="80" required
                       placeholder="例如：新用户专享 20 元券"
                       value="<?= e($v('name')) ?>">
                <small>建议写清优惠力度与用途，方便后续在列表中辨认。</small>
            </div>
        </div>

        <div class="form-row">
            <label>优惠类型 <em>*</em></label>
            <div class="form-control radio-group">
                <label class="radio">
                    <input type="radio" name="type" value="reduce" <?= $curType !== 'discount' ? 'checked' : '' ?>
                           onchange="onTypeChange()">
                    <span>满减券 —— 满足门槛后直接减固定金额</span>
                </label>
                <label class="radio">
                    <input type="radio" name="type" value="discount" <?= $curType === 'discount' ? 'checked' : '' ?>
                           onchange="onTypeChange()">
                    <span>折扣券 —— 按比例打折，可设置最多减多少</span>
                </label>
            </div>
        </div>

        <div class="form-row">
            <label id="lblValue"><span class="v-reduce">减免金额（元）<em>*</em></span><span class="v-discount">折扣率 <em>*</em></span></label>
            <div class="form-control">
                <input type="text" name="value" id="fValue" required value="<?= e($v('value', '20')) ?>">
                <small class="tip-reduce">例如填 20 表示「减 ¥20.00」。</small>
                <small class="tip-discount">填小数：<b>0.85</b> = 85 折 / <b>0.9</b> = 9 折，取值范围 0.01 ~ 0.99。</small>
            </div>
        </div>

        <div class="form-row">
            <label>使用门槛（元）</label>
            <div class="form-control">
                <input type="text" name="min_amount" value="<?= e($v('min_amount', '0')) ?>">
                <small>
                    订单金额达到该值才可使用，填 <b>0</b> 表示无门槛。
                    注意：<b>满减券的门槛不能低于减免金额</b>，否则会出现「0 元购」。
                </small>
            </div>
        </div>

        <div class="form-row row-max-discount">
            <label>折扣封顶（元）</label>
            <div class="form-control">
                <input type="text" name="max_discount" value="<?= e($v('max_discount', '0')) ?>">
                <small>仅折扣券生效，填 <b>0</b> 表示不封顶。例如 85 折但最多只能减 ¥50.00。</small>
            </div>
        </div>

        <div class="form-row">
            <label>每人限用次数 <em>*</em></label>
            <div class="form-control">
                <input type="number" name="per_user_limit" min="1"
                       max="<?= (int)\App\Models\Coupon::MAX_PER_USER ?>"
                       required value="<?= e($v('per_user_limit', '1')) ?>">
                <small>
                    同一位用户最多能持有（并使用）几张，范围 1 ~
                    <b><?= (int)\App\Models\Coupon::MAX_PER_USER ?></b>。
                    系统在发放与自主领取时都会强制校验该上限。
                </small>
            </div>
        </div>

        <div class="form-row">
            <label>发放总量</label>
            <div class="form-control">
                <input type="number" name="received_limit" min="0" max="999999"
                       value="<?= e($v('received_limit', '0')) ?>">
                <small>最多能被领走多少张，填 <b>0</b> 表示不限量。</small>
            </div>
        </div>

        <div class="form-row">
            <label>适用范围 <em>*</em></label>
            <div class="form-control radio-group">
                <label class="radio">
                    <input type="radio" name="scope" value="0" id="scopeAll"
                           <?= $curScope !== '1' ? 'checked' : '' ?> onchange="onScopeChange()">
                    <span>全场通用 —— 所有商品均可使用</span>
                </label>
                <label class="radio">
                    <input type="radio" name="scope" value="1" id="scopeProduct"
                           <?= $curScope === '1' ? 'checked' : '' ?> onchange="onScopeChange()">
                    <span>指定商品 —— 仅勾选的商品可使用</span>
                </label>
            </div>
        </div>

        <div class="form-row row-products" id="wrapProducts">
            <label>适用商品（可多选）</label>
            <div class="form-control">
                <?php if (!$products): ?>
                    <div class="empty-line">暂无商品，请先到「商品管理」添加</div>
                <?php else: ?>
                <div class="pick-list">
                    <?php foreach ($products as $p): ?>
                    <label class="pick-item">
                        <input type="checkbox" name="product_ids[]" value="<?= (int)$p['id'] ?>"
                               <?= in_array((int)$p['id'], array_map('intval', $scopeIds), true) ? 'checked' : '' ?>>
                        <span>
                            <?= e($p['name']) ?>
                            <em>¥<?= money($p['price']) ?></em>
                            <?php if ((int)$p['status'] !== 1): ?><i class="badge badge-muted">已下架</i><?php endif; ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small>只勾选需要使用该券的商品；改回「全场通用」后此处的勾选会被清空。</small>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <label>生效时间</label>
            <div class="form-control">
                <input type="datetime-local" name="start_at"
                       value="<?= e(str_replace(' ', 'T', substr((string)$v('start_at'), 0, 16))) ?>">
                <small>留空表示立即生效。</small>
            </div>
        </div>

        <div class="form-row">
            <label>失效时间</label>
            <div class="form-control">
                <input type="datetime-local" name="expires_at"
                       value="<?= e(str_replace(' ', 'T', substr((string)$v('expires_at'), 0, 16))) ?>">
                <small>留空表示永久有效；到期后券自动不可用。</small>
            </div>
        </div>

        <div class="form-row">
            <label>指定券码（可选）</label>
            <div class="form-control">
                <input type="text" name="code" maxlength="20" style="text-transform:uppercase"
                       placeholder="4~20 位字母或数字，留空自动生成"
                       value="<?= e(strpos((string)$v('code'), '__') === 0 ? '' : $v('code')) ?>">
                <small>券码用于后台查找与识别，前台结算不需要用户输入，可留空。</small>
            </div>
        </div>

        <div class="form-row">
            <label>自主领取</label>
            <div class="form-control">
                <label class="radio">
                    <input type="checkbox" name="claimable" value="1" <?= $claimable ? 'checked' : '' ?>>
                    <span>开放前台自主领取（展示在「领券中心」）</span>
                </label>
                <small>不勾选则只能由管理员在后台定向发放给指定用户。</small>
            </div>
        </div>

        <div class="form-row">
            <label>状态</label>
            <div class="form-control radio-group">
                <label class="radio">
                    <input type="radio" name="status" value="1" <?= $curStatus !== '0' ? 'checked' : '' ?>>
                    <span>启用 —— 用户可在结算时使用</span>
                </label>
                <label class="radio">
                    <input type="radio" name="status" value="0" <?= $curStatus === '0' ? 'checked' : '' ?>>
                    <span>停用 —— 已发出去的券也会立即失效</span>
                </label>
            </div>
        </div>

        <div class="form-row">
            <label>备注（可选）</label>
            <div class="form-control">
                <input type="text" name="remark" maxlength="255" placeholder="例如：双十一活动专用"
                       value="<?= e($v('remark')) ?>">
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $isEdit ? '保存修改' : '创建优惠券' ?></button>
            <a class="btn btn-ghost" href="<?= url('admin/coupon/list') ?>">取消</a>
        </div>
    </form>
</div>

<script>
function onTypeChange() {
    var isDiscount = document.querySelector('input[name=type]:checked').value === 'discount';
    document.querySelectorAll('.v-reduce').forEach(function (el) { el.style.display = isDiscount ? 'none' : ''; });
    document.querySelectorAll('.v-discount').forEach(function (el) { el.style.display = isDiscount ? '' : 'none'; });
    document.querySelectorAll('.tip-reduce').forEach(function (el) { el.style.display = isDiscount ? 'none' : ''; });
    document.querySelectorAll('.tip-discount').forEach(function (el) { el.style.display = isDiscount ? '' : 'none'; });
    document.querySelectorAll('.row-max-discount').forEach(function (el) { el.style.display = isDiscount ? '' : 'none'; });

    var fv = document.getElementById('fValue');
    fv.placeholder = isDiscount ? '0.85' : '20';
}
function onScopeChange() {
    var byProduct = document.getElementById('scopeProduct').checked;
    document.getElementById('wrapProducts').style.display = byProduct ? '' : 'none';
}
(function () {
    document.querySelectorAll('input[name=type]').forEach(function (el) {
        el.addEventListener('change', onTypeChange);
    });
    onTypeChange();
    onScopeChange();
})();
</script>
