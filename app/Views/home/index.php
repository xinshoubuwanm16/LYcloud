<?php /** 首页 */
$siteName = $__site['site_name'] ?? 'LY云计算';
?>

<section class="hero">
    <div class="wrap hero-inner">
        <div class="hero-text">
            <div class="hero-badge">IPv6 线路 · 支付后自动交付</div>
            <h1><?= e($siteName) ?><br><span>IPv6 宝塔面板主机</span></h1>
            <p class="hero-sub">
                下单支付完成后，系统从库存中分配面板登录地址、账号与密码，<br>
                在订单详情页直接展示，无需提交工单等待人工开通。
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="<?= url('product/list') ?>">查看在售套餐</a>
                <a class="btn btn-outline btn-lg" href="#products">套餐与价格</a>
            </div>

            <div class="hero-stats">
                <div><strong><?= number_format($stats['products']) ?></strong><span>在售套餐</span></div>
                <div><strong><?= number_format($stats['stock']) ?></strong><span>可用库存</span></div>
                <div><strong><?= number_format($stats['orders']) ?></strong><span>累计订单</span></div>
                <div><strong><?= number_format($stats['users']) ?></strong><span>注册用户</span></div>
            </div>
        </div>

        <aside class="hero-spec">
            <div class="spec-head"><span class="spec-led"></span>交付内容</div>
            <dl class="spec-list">
                <div>
                    <dt>面板登录链接</dt>
                    <dd class="is-key">http://[IPv6]:8888/xxxx</dd>
                </div>
                <div>
                    <dt>面板账号</dt>
                    <dd>库存中随机分配</dd>
                </div>
                <div>
                    <dt>面板密码</dt>
                    <dd>库存中随机分配</dd>
                </div>
                <div>
                    <dt>交付时间</dt>
                    <dd>支付成功后即时</dd>
                </div>
                <div>
                    <dt>查看位置</dt>
                    <dd>我的订单 → 订单详情</dd>
                </div>
            </dl>
            <div class="spec-foot">面板信息支持逐项复制或一键复制全部</div>
        </aside>
    </div>
</section>

<section class="features wrap">
    <div class="feature">
        <div class="f-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        </div>
        <h3>自动交付</h3>
        <p>支付回调成功后立即从库存分配面板信息，订单详情页可查看、可复制。</p>
    </div>
    <div class="feature">
        <div class="f-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </div>
        <h3>支付通道</h3>
        <p>支持支付宝当面付与电脑网站支付；已开通易支付时可选用微信、QQ 钱包。</p>
    </div>
    <div class="feature">
        <div class="f-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 2.5 15.4 0 18M12 3c-2.5 2.6-2.5 15.4 0 18"/></svg>
        </div>
        <h3>IPv6 线路</h3>
        <p>面板与主机均提供 IPv6 地址，下单前请确认本地网络已支持 IPv6 访问。</p>
    </div>
    <div class="feature">
        <div class="f-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="1"/><rect x="3" y="13" width="18" height="7" rx="1"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>
        </div>
        <h3>面板环境</h3>
        <p>预装宝塔 Linux 面板，可自行管理网站、数据库、FTP 与 SSL 证书。</p>
    </div>
</section>

<section class="products-section wrap" id="products">
    <?php if (!empty($categories)): ?>
    <nav class="cat-entry" aria-label="商品分类快捷入口">
        <?php foreach ($categories as $c):
            $ico   = \App\Models\Category::iconPath((string)$c['icon']);
            $style = \App\Models\Category::colorStyle($c);
        ?>
        <a class="cat-entry-card"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?>
           href="<?= url('product/list', ['category' => (int)$c['id']]) ?>">
            <?php if ($ico !== ''): ?>
            <span class="ce-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24"><?= $ico /* 白名单键，路径为程序内置常量 */ ?></svg>
            </span>
            <?php endif; ?>
            <span class="ce-body">
                <b><?= e($c['name']) ?></b>
                <?php if (trim((string)$c['description']) !== ''): ?>
                    <em><?= e($c['description']) ?></em>
                <?php endif; ?>
            </span>
            <span class="ce-count"><?= (int)$c['product_count'] ?> 款</span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <div class="section-head">
        <h2>在售套餐</h2>
        <p>价格与库存实时同步，库存耗尽的套餐无法下单</p>
    </div>

    <?php if (!$products): ?>
        <div class="empty-state">暂无上架商品</div>
    <?php else: ?>
    <div class="product-grid">
        <?php foreach ($products as $p): 
            $tags = array_filter(array_map('trim', explode(',', $p['tags'])));
            $available = (int)$p['stock_mode'] === 1 ? $p['stock_count'] : 9999;
        ?>
        <div class="product-card">
            <?php if ($p['original_price'] > 0 && $p['original_price'] > $p['price']): ?>
                <div class="discount-badge">
                    省 <?= money($p['original_price'] - $p['price']) ?> 元
                </div>
            <?php endif; ?>

            <div class="product-head">
                <h3><?= e($p['name']) ?></h3>
                <?php if ($p['subtitle'] !== ''): ?>
                    <p class="product-subtitle"><?= e($p['subtitle']) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($tags): ?>
            <div class="product-tags">
                <?php foreach ($tags as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="product-price">
                <span class="cur">¥</span><span class="num"><?= money($p['price']) ?></span>
                <?php if ($p['original_price'] > $p['price']): ?>
                    <span class="origin">¥<?= money($p['original_price']) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($p['spec'] !== ''): ?>
                <div class="product-spec"><?= e($p['spec']) ?></div>
            <?php endif; ?>

            <ul class="product-meta">
                <li><span>可用库存</span>
                    <b class="<?= $available <= 0 ? 'text-danger' : 'text-ok' ?>">
                        <?= (int)$p['stock_mode'] === 1 ? $available . ' 台' : '不限量' ?>
                    </b>
                </li>
                <li><span>发货方式</span><b><?= (int)$p['auto_deliver'] === 1 ? '自动发货' : '人工发货' ?></b></li>
                <li><span>已售</span><b><?= number_format((int)$p['sales']) ?> 台</b></li>
            </ul>

            <a class="btn btn-primary btn-block <?= ($available <= 0 && (int)$p['stock_mode'] === 1) ? 'disabled' : '' ?>"
               href="<?= url('product/show', ['id' => (int)$p['id']]) ?>">
                <?= ($available <= 0 && (int)$p['stock_mode'] === 1) ? '暂时缺货' : '立即购买' ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="steps-section">
    <div class="wrap">
        <div class="section-head">
            <h2>开通流程</h2>
            <p>全程自助完成，无需人工介入</p>
        </div>
        <div class="flow">
            <div class="flow-item">
                <div class="flow-num">01</div>
                <h4>选择套餐</h4>
                <p>在商品列表中确认配置、有效期与库存</p>
            </div>
            <div class="flow-item">
                <div class="flow-num">02</div>
                <h4>注册登录</h4>
                <p>使用邮箱注册账号并登录</p>
            </div>
            <div class="flow-item">
                <div class="flow-num">03</div>
                <h4>完成支付</h4>
                <p>支付宝扫码或跳转收银台付款</p>
            </div>
            <div class="flow-item">
                <div class="flow-num">04</div>
                <h4>获取面板</h4>
                <p>订单详情页查看并复制面板登录信息</p>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($__site['site_notice'])): ?>
<section class="notice-section wrap">
    <div class="notice-box">
        <h3>购买须知</h3>
        <p><?= nl2br(e($__site['site_notice'])) ?></p>
    </div>
</section>
<?php endif; ?>
