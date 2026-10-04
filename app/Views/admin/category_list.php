<?php
/** 后台商品分类列表 */
$rows  = $rows ?? [];
$stats = $stats ?? ['total' => 0, 'active' => 0, 'products' => 0, 'uncategorized' => 0];
?>
<div class="card">
    <div class="card-head">
        <h3>商品分类 <small>共 <?= (int)$stats['total'] ?> 个</small></h3>
        <div class="head-actions">
            <a class="btn btn-sm btn-primary" href="<?= url('admin/category/form') ?>">新增分类</a>
        </div>
    </div>

    <!-- 统计 -->
    <div class="stat-row">
        <div class="stat-box"><span>分类总数</span><b><?= (int)$stats['total'] ?></b></div>
        <div class="stat-box"><span>启用中</span><b class="c-ok"><?= (int)$stats['active'] ?></b></div>
        <div class="stat-box"><span>商品总数</span><b><?= (int)$stats['products'] ?></b></div>
        <div class="stat-box"><span>未分类商品</span>
            <b class="<?= (int)$stats['uncategorized'] > 0 ? 'c-warn' : '' ?>"><?= (int)$stats['uncategorized'] ?></b>
        </div>
    </div>

    <p class="form-tip" style="margin:0 22px 14px;">
        分类用于前台「全部商品」页的筛选标签与首页分类入口。排序值越大越靠前，也可用右侧
        <b>↑ ↓</b> 直接调整相邻顺序。分类<b>隐藏</b>后前台不再展示入口，但已属该分类的商品在「全部商品」页照常可见。
    </p>

    <?php if (!$rows): ?>
        <div class="empty-line">还没有分类，点击右上角「新增分类」创建第一个</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th style="width:56px">ID</th>
                <th style="width:210px">分类名称</th>
                <th style="width:130px">标识 slug</th>
                <th>描述</th>
                <th style="width:100px">商品数</th>
                <th style="width:70px">排序</th>
                <th style="width:80px">状态</th>
                <th style="width:250px">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): ?>
            <?php
                $cid     = (int)$c['id'];
                $on      = (int)$c['status'] === 1;
                $used    = (int)($c['use_count'] ?? 0);
                $activeN = (int)($c['active_use_count'] ?? 0);
                $style   = \App\Models\Category::colorStyle($c);
                $iconPath = \App\Models\Category::iconPath((string)$c['icon']);
            ?>
            <tr>
                <td class="mono"><?= $cid ?></td>
                <td>
                    <span class="cat-name-cell"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?>>
                        <?php if ($iconPath !== ''): ?>
                        <span class="cat-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><?= $iconPath /* 白名单键，路径为程序内置常量 */ ?></svg>
                        </span>
                        <?php endif; ?>
                        <b><?= e($c['name']) ?></b>
                    </span>
                </td>
                <td class="mono"><?= e($c['slug']) ?></td>
                <td class="c-muted"><?= e($c['description']) !== '' ? e($c['description']) : '—' ?></td>
                <td>
                    <?php if ($used === 0): ?>
                        <span class="c-muted">0</span>
                    <?php else: ?>
                        <?= $used ?>
                        <small class="c-muted">（上架 <?= $activeN ?>）</small>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= (int)$c['sort'] ?></td>
                <td>
                    <span class="badge <?= $on ? 'badge-ok' : 'badge-muted' ?>"><?= $on ? '启用' : '隐藏' ?></span>
                </td>
                <td class="ops">
                    <form method="post" action="<?= url('admin/category/move') ?>" class="inline">
                        <?= \App\Auth::csrfField() ?>
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input type="hidden" name="dir" value="up">
                        <button class="btn btn-sm btn-ghost" type="submit" title="上移">↑</button>
                    </form>
                    <form method="post" action="<?= url('admin/category/move') ?>" class="inline">
                        <?= \App\Auth::csrfField() ?>
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input type="hidden" name="dir" value="down">
                        <button class="btn btn-sm btn-ghost" type="submit" title="下移">↓</button>
                    </form>

                    <a class="btn btn-sm btn-ghost" href="<?= url('admin/category/form', ['id' => $cid]) ?>">编辑</a>

                    <form method="post" action="<?= url('admin/category/toggle') ?>" class="inline">
                        <?= \App\Auth::csrfField() ?>
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <button class="btn btn-sm btn-ghost" type="submit"><?= $on ? '隐藏' : '启用' ?></button>
                    </form>

                    <form method="post" action="<?= url('admin/category/delete') ?>" class="inline js-confirm"
                          data-confirm="<?= $used > 0
                              ? '该分类下仍有 ' . $used . ' 个商品，删除后这些商品会变为「未分类」。确认继续？'
                              : '确认删除分类「' . e($c['name']) . '」？' ?>">
                        <?= \App\Auth::csrfField() ?>
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input type="hidden" name="force" value="<?= $used > 0 ? 1 : 0 ?>">
                        <button class="btn btn-sm btn-danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card-head"><h3>使用提示</h3></div>
    <div class="cat-tips">
        <ul>
            <li><b>未分类商品</b>：商品表单里分类留空即为「未分类」，会在「全部商品」页的「全部」标签下展示，但不出现在任何分类标签里。</li>
            <li><b>删除保护</b>：分类下还有商品时，直接删除会被拒绝并提示数量；列表页的删除按钮会带「强制」参数，确认后会先把旗下商品置为未分类再删除分类——<b>不会删除商品本身</b>。</li>
            <li><b>slug 自动生成</b>：留空时由名称自动生成（英文数字），纯中文名会回落为 <code>cat-{ID}</code>；重名自动追加 <code>-2</code>、<code>-3</code>。</li>
            <li><b>强调色</b>：填入 HEX（如 <code>#6fb2e8</code>）后，该分类在前台标签与列表页会使用这个颜色，留空则跟随站点主题粉。</li>
        </ul>
    </div>
</div>
