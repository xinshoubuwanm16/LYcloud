<?php
/** 商品分类 编辑/新增 */
$isEdit = (bool)$cat;
$v = function ($key, $default = '') use ($cat) {
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return $cat[$key] ?? $default;
};
$icons    = $icons ?? \App\Models\Category::ICONS;
$useCount = (int)($useCount ?? 0);
$curIcon  = \App\Models\Category::normalizeIcon((string)$v('icon'));
?>
<div class="card">
    <div class="card-head">
        <h3><?= $isEdit ? '编辑分类' : '添加分类' ?></h3>
        <a class="btn btn-sm btn-ghost" href="<?= url('admin/category/list') ?>">← 返回列表</a>
    </div>

    <form method="post" action="<?= url('admin/category/save') ?>" class="form-horizontal">
        <?= \App\Auth::csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)($cat['id'] ?? 0) ?>">

        <div class="form-row">
            <label>分类名称 <em>*</em></label>
            <div class="form-control">
                <input type="text" name="name" value="<?= e($v('name')) ?>"
                       placeholder="如：虚拟主机 / 高防服务器 / 域名注册" required maxlength="60">
                <small>显示在前台筛选标签与首页分类入口上，最多 60 字</small>
            </div>
        </div>

        <div class="form-row">
            <label>英文标识 slug</label>
            <div class="form-control">
                <input type="text" name="slug" value="<?= e($v('slug')) ?>"
                       placeholder="留空自动生成，如：host / vps" maxlength="60">
                <small>
                    用于前台链接锚点与筛选参数。留空时自动由名称生成（仅保留英文数字）；
                    纯中文名会回落为 <code>cat-{ID}</code>，重名自动追加 <code>-2</code>。
                </small>
            </div>
        </div>

        <div class="form-row">
            <label>分类图标</label>
            <div class="form-control">
                <div class="cat-icon-picker">
                    <label class="cat-icon-opt">
                        <input type="radio" name="icon" value="" <?= $curIcon === '' ? 'checked' : '' ?>>
                        <span class="cat-icon-box"><em>无</em></span>
                    </label>
                    <?php foreach ($icons as $key => $path): ?>
                    <label class="cat-icon-opt" title="<?= e($key) ?>">
                        <input type="radio" name="icon" value="<?= e($key) ?>" <?= $curIcon === $key ? 'checked' : '' ?>>
                        <span class="cat-icon-box">
                            <svg viewBox="0 0 24 24"><?= $path /* 内置常量路径 */ ?></svg>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small>图标仅用于前台分类入口卡片，不选则只显示文字</small>
            </div>
        </div>

        <div class="form-row">
            <label>强调色</label>
            <div class="form-control">
                <div class="cat-color-row">
                    <?php /* 取色器仅作辅助输入（无 name，不参与提交），真正提交的是下面的文本框 */ ?>
                    <input type="color" id="catColor" value="<?= e($v('color') !== '' ? $v('color') : '#ff8aa8') ?>">
                    <input type="text" name="color" id="catColorText" value="<?= e($v('color')) ?>" placeholder="留空跟随主题粉，或填 #6fb2e8" maxlength="7" style="max-width:220px">
                    <button type="button" class="btn btn-sm btn-ghost" id="catColorClear">清除</button>
                </div>
                <small>留空 = 跟随站点主题樱花粉；填写后该分类的标签与图标会使用此颜色</small>
            </div>
        </div>

        <div class="form-row">
            <label>分类描述</label>
            <div class="form-control">
                <input type="text" name="description" value="<?= e($v('description')) ?>"
                       placeholder="一句话说明，如：开箱即用的 IPv6 宝塔面板主机" maxlength="255">
                <small>显示在首页分类入口卡片的标题下方，可留空</small>
            </div>
        </div>

        <div class="form-row">
            <label>排序</label>
            <div class="form-control">
                <input type="number" name="sort" value="<?= e($v('sort', '0')) ?>" step="1" min="-9999" max="9999" style="width:140px">
                <small>数值越大越靠前；也可以回列表用 ↑ ↓ 直接调整相邻顺序</small>
            </div>
        </div>

        <div class="form-row">
            <label>状态</label>
            <div class="form-control">
                <label class="radio">
                    <input type="radio" name="status" value="1" <?= (int)$v('status', 1) === 1 ? 'checked' : '' ?>>
                    启用（前台展示该分类入口与筛选标签）
                </label>
                <label class="radio">
                    <input type="radio" name="status" value="0" <?= (int)$v('status', 1) === 0 ? 'checked' : '' ?>>
                    隐藏（前台不展示入口，旗下商品在「全部商品」页仍可见）
                </label>
            </div>
        </div>

        <?php if ($isEdit && $useCount > 0): ?>
        <div class="form-row">
            <label>当前使用</label>
            <div class="form-control">
                <div class="flash flash-info" style="margin:0;">
                    该分类下已有 <b><?= $useCount ?></b> 个商品。
                    删除分类不会删除商品，只会把它们置为「未分类」。
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $isEdit ? '保存修改' : '创建分类' ?></button>
            <a class="btn btn-ghost" href="<?= url('admin/category/list') ?>">取消</a>
        </div>
    </form>
</div>

<script>
(function () {
    var color = document.getElementById('catColor');
    var text  = document.getElementById('catColorText');
    var clear = document.getElementById('catColorClear');
    if (!color || !text) return;

    // 取色器 → 文本框
    color.addEventListener('input', function () { text.value = color.value; });
    // 文本框 → 取色器（仅合法 HEX 才同步，避免抖动）
    text.addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(text.value.trim())) color.value = text.value.trim();
    });
    if (clear) {
        clear.addEventListener('click', function () { text.value = ''; });
    }
})();
</script>
