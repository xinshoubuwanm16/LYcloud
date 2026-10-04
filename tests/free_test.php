<?php
/**
 * 0 元商品测试（1.7.10 新增）
 *
 * 覆盖：
 *   - 后台商品保存：price=0 成功落库（0=免费领取）、负数拒绝、
 *     空表单默认 0.00 亦可保存
 *   - 0 元商品页：价格区「免费领取」、无支付方式选择（pay-methods 隐藏）、
 *     无优惠券选择区（0 元无可折金额）、按钮与保障文案
 *   - 0 元下单即发货（核心）：免支付直接结算 —— channel=free、
 *     status=DELIVERED、trade_no=FREE- 前缀、库存分配绑定、销量+1、
 *     不扣余额、无余额扣减流水、详情页含「免费领取成功」与面板信息
 *   - 券后 0 元：满 10 减 10 全场券叠加 10 元商品 → 券后 0.00 →
 *     channel=free + 券核销（used_count+1），不产生负余额
 *   - 限购计入：0 元商品 limit_per_user=1，首单成功后二次下单被拒
 *     （购买计数含 0 元 DELIVERED 订单）
 *   - 支付页防呆：free 订单访问 pay → 302 跳详情；通道文案
 *     pay_channel_text('free') / short('free')；后台列表/详情显示「免费」
 *   - 回归：付费商品 yipay 通道不受影响（PENDING、通道保持）；
 *     纯余额支付回归（channel=balance、BAL 前缀、真实扣款流水）——
 *     确认 free 分支未污染既有结算判定
 *   - 现场还原：订单/库存/商品/券/流水/用户/管理员 全量清理 + 配置快照还原 + 自检
 *
 * 用法：LY_TEST_BASE=http://127.0.0.1:8099 php tests/free_test.php
 * 前置：站点服务已启动（php -S 127.0.0.1:8099 -t 项目根）。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\UserCoupon;

$BASE = getenv('LY_TEST_BASE') ?: 'http://127.0.0.1:8099';
$db = Database::instance();

$pass = 0;
$fail = 0;
$TAG = 'FRE' . random_int(100000, 999999);

function group(string $t): void
{
    echo PHP_EOL . '[' . $t . ']' . PHP_EOL;
}

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo '  [PASS] ' . $name . PHP_EOL;
    } else {
        $fail++;
        echo '  [FAIL] ' . $name . ($extra !== '' ? '  (' . $extra . ')' : '') . PHP_EOL;
    }
}

// ============================================================
// HTTP 辅助（与既有套件一致：httpGet 返回数字列表）
// ============================================================

function httpGet(string $url, string $jar = '', array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, (string)$body, $loc];   // [$code, $body, location]；两元素解构不受影响
}

function httpPost(string $url, array $data, string $jar = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    reloadSettings();   // HTTP 端可能写入配置，刷新本进程缓存（惯例保留）
    return ['code' => $code, 'body' => (string)$body, 'location' => $loc];
}

function grabToken(string $html): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * 刷新本进程的 Setting 静态缓存
 *
 * 站点（HTTP 端）与测试脚本是两个 PHP 进程：HTTP 端写库后
 * 本进程 Setting::$cache 不会自动同步，必须显式重载。
 */
function reloadSettings(): void
{
    $ref = new \ReflectionClass(Setting::class);
    $ref->getProperty('cache')->setValue(null, []);
    $ref->getProperty('loaded')->setValue(null, false);
    Setting::loadAll();
}

// ============================================================
// 测试数据构造
// ============================================================

function mkFreeProduct(Database $db, string $tag, string $price, int $limit): int
{
    return (int)$db->insert('ly_products', [
        'name' => $tag, 'subtitle' => $tag, 'description' => $tag,
        'price' => $price, 'original_price' => '0.00',
        'cover' => '', 'tags' => '', 'spec' => '',
        'stock_mode' => 1, 'auto_deliver' => 1,
        'duration_value' => 0, 'duration_unit' => 'month',
        'limit_per_user' => $limit,
        'sort' => 0, 'sales' => 0, 'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function mkFreeStock(Database $db, int $pid, string $tag): int
{
    return (int)$db->insert('ly_stocks', [
        'product_id'  => $pid,
        'panel_url'   => 'https://panel.example.com/' . $tag,
        'panel_user'  => 'u_' . $tag,
        'panel_pass'  => 'p_' . $tag,
        'remark'      => '', 'source' => 1, 'mn_username' => '',
        'status'      => 0, 'order_id' => 0,
    ]);
}

function mkFreeUser(Database $db, string $tag, string $balance): array
{
    $email = $tag . random_int(10000, 99999) . '@qq.com';
    $id = (int)$db->insert('ly_users', [
        'email' => $email,
        'password' => password_hash('Test1234', PASSWORD_DEFAULT),
        'nickname' => $tag,
        'balance' => $balance, 'status' => 1, 'email_verified' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return ['id' => $id, 'email' => $email];
}

/** 前台用户登录（session cookie 落入 $jar） */
function loginFront(string $BASE, string $email, string $jar): bool
{
    [, $html] = httpGet($BASE . '/index.php?r=auth/login', $jar);
    $token = grabToken($html);
    httpPost($BASE . '/index.php?r=auth/login', [
        '_token' => $token, 'email' => $email, 'password' => 'Test1234',
    ], $jar);
    [$code] = httpGet($BASE . '/index.php?r=order/list', $jar);
    return $code === 200;
}

// ============================================================
// 0. 环境与快照
// ============================================================

$SNAP_KEYS = ['order_expire_min', 'coupon_enabled'];
$snap = [];
foreach ($SNAP_KEYS as $k) {
    $snap[$k] = (string)Setting::get($k, '');
}
group('0. 环境与快照');
check('现场配置快照（expire_min / coupon_enabled）', count($snap) === 2);

// 测试期间防超时关单（现场 expire_min 仅 5 分钟），第 8 节随快照还原
Setting::set('order_expire_min', '120');
check('order_expire_min 临时调大（防测试订单被自动关闭）',
    (string)Setting::get('order_expire_min', '') === '120');

// ============================================================
// 管理员、用户、商品与券
// ============================================================

$ADMIN_USER = 'fre_admin_' . substr(md5(uniqid('', true)), 0, 8);
$ADMIN_PASS = 'Fre' . random_int(100000, 999999);
$adminJar = sys_get_temp_dir() . '/fre_admin_' . random_int(10000, 99999) . '.jar';
$adminId = Admin::create($ADMIN_USER, $ADMIN_PASS, '零元测试');

[, $adminLoginHtml] = httpGet($BASE . '/index.php?r=admin/auth/login', $adminJar);
httpPost($BASE . '/index.php?r=admin/auth/login', [
    '_token' => grabToken($adminLoginHtml),
    'username' => $ADMIN_USER, 'password' => $ADMIN_PASS,
], $adminJar);
[, $chkAdmin] = httpGet($BASE . '/index.php?r=admin/product/list', $adminJar);
check('管理员登录（可访问后台商品列表）', strpos($chkAdmin, '商品管理') !== false);

$user1 = mkFreeUser($db, $TAG . '_u1', '50.00');   // 有余额：验证 0 元单不扣余额 + 纯余额回归
$user2 = mkFreeUser($db, $TAG . '_u2', '0.00');    // 无余额：券后 0 元与限购
$jar1 = sys_get_temp_dir() . '/fre_u1_' . random_int(10000, 99999) . '.jar';
$jar2 = sys_get_temp_dir() . '/fre_u2_' . random_int(10000, 99999) . '.jar';
$u1ok = loginFront($BASE, $user1['email'], $jar1);
$u2ok = loginFront($BASE, $user2['email'], $jar2);
check('前台用户登录（u1 有余额 / u2 零余额）', $u1ok && $u2ok);

// 模型直插商品：pFree1 普通零元 / pFree2 限购零元 / pPaid 付费（券后 0 元与回归用）
$pFree1 = mkFreeProduct($db, $TAG . '_免费A', '0.00', 0);
$pFree2 = mkFreeProduct($db, $TAG . '_免费限购B', '0.00', 1);
$pPaid  = mkFreeProduct($db, $TAG . '_付费C', '10.00', 0);
for ($i = 0; $i < 3; $i++) {
    mkFreeStock($db, $pFree1, $TAG . '_sa' . $i);
}
for ($i = 0; $i < 2; $i++) {
    mkFreeStock($db, $pFree2, $TAG . '_sb' . $i);
}
for ($i = 0; $i < 5; $i++) {
    mkFreeStock($db, $pPaid, $TAG . '_sc' . $i);
}
check('测试商品与库存就绪（0 元×2 + 付费×1，库存 3/2/5）',
    $pFree1 > 0 && $pFree2 > 0 && $pPaid > 0
    && (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id=? AND status=0', [$pFree1]) === 3
    && (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id=? AND status=0', [$pFree2]) === 2
    && (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id=? AND status=0', [$pPaid]) === 5);

// 满 10 减 10 全场券（门槛=面额合法，10 元商品用后恰好 0 元）
$rCoupon = \App\Models\Coupon::create([
    'name' => $TAG . '_满减清零', 'type' => 'reduce', 'value' => '10', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 2, 'received_limit' => 100,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '',
    'code' => 'FRE' . strtoupper(substr(md5(uniqid('', true)), 0, 8)), 'remark' => 'test',
], [], $adminId);
$couponId = (int)($rCoupon['id'] ?? 0);
UserCoupon::grant($couponId, [$user2['id']], UserCoupon::SOURCE_ADMIN);
$ucRow = $db->first('SELECT id, status FROM ly_user_coupons WHERE coupon_id=? AND user_id=?', [$couponId, $user2['id']]);
$ucId = $ucRow ? (int)$ucRow['id'] : 0;
check('满 10 减 10 全场券创建并发放给 u2', $couponId > 0 && $ucId > 0 && (int)$ucRow['status'] === 0);

/** 商品页 CSRF（下单表单；默认 u1 jar，u2 断言时显式传 jar2） */
$csrfProduct = function (int $pid, ?string $jar = null) use ($BASE, $jar1): string {
    [, $html] = httpGet($BASE . '/index.php?r=product/show&id=' . $pid, $jar ?? $jar1);
    return grabToken($html);
};
/** 后台订单详情页 CSRF */
$csrfOrder = function (int $oid) use ($BASE, $adminJar): string {
    [, $html] = httpGet($BASE . '/index.php?r=admin/order/detail&id=' . $oid, $adminJar);
    return grabToken($html);
};

$createdOrders = [];   // 所有测试订单 id（第 8 节清理）

// ============================================================
// 1. 后台商品保存：0 元边界
// ============================================================

group('1. 后台商品保存（0 元边界）');

/** 后台商品表单 CSRF */
$csrfAdminForm = function () use ($BASE, $adminJar): string {
    [, $html] = httpGet($BASE . '/index.php?r=admin/product/form', $adminJar);
    return grabToken($html);
};
$adminSaveProduct = function (string $name, string $price) use ($BASE, $adminJar, $csrfAdminForm): array {
    return httpPost($BASE . '/index.php?r=admin/product/save', [
        '_token'          => $csrfAdminForm(),
        'name'            => $name,
        'subtitle'        => '',
        'description'     => '',
        'price'           => $price,
        'original_price'  => '0',
        'cover'           => '',
        'tags'            => '',
        'spec'            => '',
        'stock_mode'      => '1',
        'auto_deliver'    => '1',
        'deliver_mode'    => '1',
        'sort'            => '0',
        'sales'           => '0',
        'status'          => '1',
        'duration_value'  => '0',
        'duration_unit'   => 'month',
        'limit_per_user'  => '0',
        'category_id'     => '0',
    ], $adminJar);
};

$r = $adminSaveProduct($TAG . '_后台零元', '0');
$adminFree = $db->first('SELECT id, price FROM ly_products WHERE name=?', [$TAG . '_后台零元']);
check('后台保存 price=0 商品 → 302 回列表且落库 0.00',
    (int)$r['code'] === 302 && strpos($r['location'], 'r=admin/product/list') !== false
    && $adminFree !== null && (string)$adminFree['price'] === '0.00',
    isset($r['code']) ? 'loc=' . $r['location'] : '');

[, $listHtml] = httpGet($BASE . '/index.php?r=admin/product/list', $adminJar);
check('后台列表含成功提示「商品已添加」', strpos($listHtml, '商品已添加') !== false);

$r = $adminSaveProduct($TAG . '_负价', '-5');
$negExists = $db->value('SELECT COUNT(*) FROM ly_products WHERE name=?', [$TAG . '_负价']);
[, $listHtml2] = httpGet($BASE . '/index.php?r=admin/product/list', $adminJar);
check('后台保存 price=-5 → 302 回表单且拒绝落库',
    (int)$r['code'] === 302 && strpos($r['location'], 'r=admin/product/form') !== false
    && (int)$negExists === 0,
    'loc=' . $r['location']);
check('负数拒绝提示「售价不能为负数（填写 0 表示免费领取）」',
    strpos($listHtml2, '售价不能为负数') !== false && strpos($listHtml2, '免费领取') !== false);

// ============================================================
// 2. 0 元商品页展示
// ============================================================

group('2. 0 元商品页展示');

[, $prodHtml] = httpGet($BASE . '/index.php?r=product/show&id=' . $pFree1, $jar1);
check('0 元商品页价格区显示「免费领取」', strpos($prodHtml, '免费领取') !== false);
check('保障区显示「0 元商品，免支付直接发货」',
    strpos($prodHtml, '0 元商品，免支付直接发货') !== false);
check('无支付方式选择区（不含「选择支付方式」标题）',
    strpos($prodHtml, '选择支付方式') === false);
check('无任何支付通道单选框（name="pay_channel" 隐藏）',
    strpos($prodHtml, 'name="pay_channel"') === false);
check('无优惠券选择区（0 元无可折金额，couponBox 隐藏）',
    strpos($prodHtml, 'id="couponBox"') === false);

// 对照：付费商品页保留完整支付区（含捐赠/易支付通道）
[, $paidHtml] = httpGet($BASE . '/index.php?r=product/show&id=' . $pPaid, $jar1);
check('付费商品页仍渲染支付方式与通道单选框',
    strpos($paidHtml, '选择支付方式') !== false && strpos($paidHtml, 'name="pay_channel"') !== false);

// ============================================================
// 3. 0 元下单即发货（核心链路）
// ============================================================

group('3. 0 元下单即发货（核心链路）');

// 0 元商品页表单无 pay_channel 输入 —— 模拟真实提交：不带该字段
$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token'     => $csrfProduct($pFree1),
    'product_id' => $pFree1,
], $jar1);
check('0 元商品下单 → 302 直达订单详情（无支付页）',
    (int)$r['code'] === 302 && strpos($r['location'], 'r=order/detail') !== false,
    'loc=' . $r['location']);
preg_match('/id=(\d+)/', $r['location'], $mOid);
$oidFree1 = (int)($mOid[1] ?? 0);
$createdOrders[] = $oidFree1;

$row = $oidFree1 > 0 ? $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidFree1]) : null;
check('订单落库：pay_channel=free、pay_amount=0.00、balance_paid=0.00',
    $row !== null
    && (string)$row['pay_channel'] === 'free'
    && (string)$row['pay_amount'] === '0.00'
    && (string)$row['balance_paid'] === '0.00');
check('订单状态=已发货（DELIVERED），paid_at 非空',
    $row !== null && (int)$row['status'] === Order::STATUS_DELIVERED
    && !empty($row['paid_at']));
check('交易号 FREE- 前缀（FREE-订单号）',
    $row !== null && strpos((string)$row['trade_no'], 'FREE-') === 0
    && (string)$row['trade_no'] === 'FREE-' . (string)$row['order_no']);

$stockRow = $row !== null && (int)$row['stock_id'] > 0
    ? $db->first('SELECT status, order_id FROM ly_stocks WHERE id=?', [(int)$row['stock_id']])
    : null;
check('库存已分配绑定（status=1 已售 + order_id 指向订单）',
    $stockRow !== null && (int)$stockRow['status'] === 1 && (int)$stockRow['order_id'] === $oidFree1);
check('商品销量 +1',
    (int)$db->value('SELECT sales FROM ly_products WHERE id=?', [$pFree1]) === 1);

$balNow = (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$user1['id']]);
$balLogs = (int)$db->value(
    "SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=? AND type='consume'",
    [$user1['id']]
);
check('用户余额未被扣减（仍 50.00）且无消费流水',
    $balNow === '50.00' && $balLogs === 0,
    'bal=' . $balNow . ' logs=' . $balLogs);

[, $detailHtml] = httpGet($BASE . '/index.php?r=order/detail&id=' . $oidFree1, $jar1);
check('详情页含「免费领取成功」提示', strpos($detailHtml, '免费领取成功') !== false);
check('详情页展示已分配面板信息（自动发货生效）',
    strpos($detailHtml, 'panel.example.com/' . $TAG . '_sa') !== false);

// ============================================================
// 4. 券后 0 元 → free 通道 + 券核销
// ============================================================

group('4. 券后 0 元（满 10 减 10 + 10 元商品）');

$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token'         => $csrfProduct($pPaid, $jar2),
    'product_id'     => $pPaid,
    'user_coupon_id' => $ucId,
], $jar2);
check('10 元商品 + 减 10 券下单 → 302 直达订单详情',
    (int)$r['code'] === 302 && strpos($r['location'], 'r=order/detail') !== false,
    'loc=' . $r['location']);
preg_match('/id=(\d+)/', $r['location'], $mOid2);
$oidCouponZero = (int)($mOid2[1] ?? 0);
$createdOrders[] = $oidCouponZero;

$row = $oidCouponZero > 0 ? $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidCouponZero]) : null;
check('券后 0 元落库：amount=10.00、coupon_discount=10.00、pay_amount=0.00',
    $row !== null
    && (string)$row['amount'] === '10.00'
    && (string)$row['coupon_discount'] === '10.00'
    && (string)$row['pay_amount'] === '0.00'
    && (string)$row['balance_paid'] === '0.00');
check('券后 0 元通道=free 且已发货（DELIVERED + FREE- 交易号）',
    $row !== null
    && (string)$row['pay_channel'] === 'free'
    && (int)$row['status'] === Order::STATUS_DELIVERED
    && strpos((string)$row['trade_no'], 'FREE-') === 0);
check('订单关联券 id 正确', $row !== null && (int)$row['coupon_id'] === $couponId);

$ucAfter = $db->first('SELECT status, order_id FROM ly_user_coupons WHERE id=?', [$ucId]);
$usedCount = (int)$db->value('SELECT used_count FROM ly_coupons WHERE id=?', [$couponId]);
check('券已核销（status=used + 绑定订单 + used_count=1）',
    $ucAfter !== null && (int)$ucAfter['status'] === 1
    && (int)$ucAfter['order_id'] === $oidCouponZero && $usedCount === 1);

$bal2 = (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$user2['id']]);
check('零余额用户券后下单不产生负余额（仍 0.00）', $bal2 === '0.00');

// 券重复使用被拒（同一张券第二次下单）
$r2 = httpPost($BASE . '/index.php?r=order/create', [
    '_token'         => $csrfProduct($pPaid, $jar2),
    'product_id'     => $pPaid,
    'user_coupon_id' => $ucId,
], $jar2);
[, $prodHtml2] = httpGet($BASE . '/index.php?r=product/show&id=' . $pPaid, $jar2);
check('已核销的券再次下单 → 拒绝回落商品页（该优惠券已被使用或已失效）',
    (int)$r2['code'] === 302 && strpos($r2['location'], 'r=product/show') !== false
    && strpos($prodHtml2, '已被使用或已失效') !== false,
    'loc=' . $r2['location']);

// ============================================================
// 5. 限购计入（0 元订单纳入购买次数）
// ============================================================

group('5. 限购计入');

$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token'     => $csrfProduct($pFree2, $jar2),
    'product_id' => $pFree2,
], $jar2);
preg_match('/id=(\d+)/', $r['location'], $mOid3);
$oidLimit = (int)($mOid3[1] ?? 0);
$createdOrders[] = $oidLimit;
$row = $oidLimit > 0 ? $db->first('SELECT pay_channel, status FROM ly_orders WHERE id=?', [$oidLimit]) : null;
check('限购 0 元商品首单成功（free + DELIVERED）',
    (int)$r['code'] === 302 && strpos($r['location'], 'r=order/detail') !== false
    && $row !== null && (string)$row['pay_channel'] === 'free'
    && (int)$row['status'] === Order::STATUS_DELIVERED,
    'loc=' . $r['location']);

$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token'     => $csrfProduct($pFree2, $jar2),
    'product_id' => $pFree2,
], $jar2);
[, $prodHtml3] = httpGet($BASE . '/index.php?r=product/show&id=' . $pFree2, $jar2);
$limitOrders = (int)$db->value(
    'SELECT COUNT(*) FROM ly_orders WHERE user_id=? AND product_id=?',
    [$user2['id'], $pFree2]
);
check('第二次下单被拒：回落商品页 + 提示「无法再次购买」+ 订单数仍为 1',
    (int)$r['code'] === 302 && strpos($r['location'], 'r=product/show') !== false
    && strpos($prodHtml3, '无法再次购买') !== false
    && $limitOrders === 1,
    'loc=' . $r['location'] . ' orders=' . $limitOrders);

// ============================================================
// 6. 支付页防呆与通道文案
// ============================================================

group('6. 支付页防呆与通道文案');

[$payCode, , $payLoc] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidFree1, $jar1);
check('free 订单访问支付页 → 302 跳转订单详情',
    $payCode === 302 && strpos((string)$payLoc, 'r=order/detail') !== false
    && strpos((string)$payLoc, 'r=order/pay') === false,
    'code=' . $payCode);

check("pay_channel_text('free') = 免费领取（0 元）",
    pay_channel_text('free') === '免费领取（0 元）',
    pay_channel_text('free'));
check("pay_channel_text_short('free') = 免费",
    pay_channel_text_short('free') === '免费',
    pay_channel_text_short('free'));

[, $adminList] = httpGet($BASE . '/index.php?r=admin/order/list', $adminJar);
check('后台订单列表 free 单通道显示「免费」', strpos($adminList, '免费') !== false);

[, $adminDetail] = httpGet($BASE . '/index.php?r=admin/order/detail&id=' . $oidFree1, $adminJar);
check('后台订单详情支付方式显示「免费领取（0 元）」',
    strpos($adminDetail, '免费领取（0 元）') !== false);

[, $userList] = httpGet($BASE . '/index.php?r=order/list', $jar1);
check('前台订单列表通道简称显示「免费」', strpos($userList, '支付方式：免费') !== false);

// ============================================================
// 7. 回归：非 0 价格链路不受影响
// ============================================================

group('7. 回归：付费链路与纯余额');

// 易支付通道回归：付费商品正常进入待支付
$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token'     => $csrfProduct($pPaid, $jar2),
    'product_id' => $pPaid,
    'pay_channel' => 'yipay_alipay',
], $jar2);
preg_match('/id=(\d+)/', $r['location'], $mOid4);
$oidYipay = (int)($mOid4[1] ?? 0);
$createdOrders[] = $oidYipay;
$row = $oidYipay > 0 ? $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidYipay]) : null;
check('付费商品 yipay 下单 → 保持 yipay_alipay 通道待支付（未被误判 free）',
    $row !== null
    && (string)$row['pay_channel'] === 'yipay_alipay'
    && (int)$row['status'] === Order::STATUS_PENDING
    && (string)$row['pay_amount'] === '10.00'
    && (string)$row['trade_no'] === '',
    $row ? 'ch=' . $row['pay_channel'] . ' st=' . $row['status'] : 'null');

[$payPageCode, $payPage] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidYipay, $jar2);
check('yipay 订单支付页正常渲染（200）', $payPageCode === 200, 'code=' . $payPageCode);

// 纯余额回归：u1 用余额全额抵扣 10 元商品 → balance 通道（原逻辑不受 free 分支影响）
$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token'      => $csrfProduct($pPaid),
    'product_id'  => $pPaid,
    'pay_channel' => 'yipay_alipay',
    'use_balance' => '1',
], $jar1);
preg_match('/id=(\d+)/', $r['location'], $mOid5);
$oidBal = (int)($mOid5[1] ?? 0);
$createdOrders[] = $oidBal;
$row = $oidBal > 0 ? $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidBal]) : null;
check('纯余额下单 → channel=balance、BAL 前缀交易号、balance_paid=10.00',
    $row !== null
    && (string)$row['pay_channel'] === 'balance'
    && (int)$row['status'] === Order::STATUS_DELIVERED
    && strpos((string)$row['trade_no'], 'BAL') === 0
    && (string)$row['balance_paid'] === '10.00',
    $row ? 'ch=' . $row['pay_channel'] . ' tn=' . $row['trade_no'] : 'null');
check('纯余额真实扣款：余额 50 → 40 且生成 consume 流水',
    (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$user1['id']]) === '40.00'
    && (int)$db->value("SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=? AND type='consume' AND ref_id=?", [$user1['id'], $oidBal]) === 1);

// ============================================================
// 8. 清理与自检
// ============================================================

group('8. 清理与自检');

// ---------- 配置还原 ----------
foreach ($snap as $k => $v) {
    Setting::set($k, $v);
}

// ---------- 数据清理 ----------
$ids = implode(',', array_filter(array_map('intval', array_unique($createdOrders))));
if ($ids !== '') {
    $db->delete('ly_orders', "id IN ($ids)");
}
$db->query('DELETE FROM ly_stocks WHERE product_id IN (?,?,?,?)', [$pFree1, $pFree2, $pPaid, (int)($adminFree['id'] ?? 0)]);
$db->query('DELETE FROM ly_products WHERE id IN (?,?,?,?)', [$pFree1, $pFree2, $pPaid, (int)($adminFree['id'] ?? 0)]);
$db->query('DELETE FROM ly_user_coupons WHERE user_id IN (?,?)', [$user1['id'], $user2['id']]);
$db->query('DELETE FROM ly_coupon_scopes WHERE coupon_id=?', [$couponId]);
$db->query('DELETE FROM ly_coupons WHERE id=?', [$couponId]);
$db->query('DELETE FROM ly_balance_logs WHERE user_id IN (?,?)', [$user1['id'], $user2['id']]);
$db->query('DELETE FROM ly_users WHERE id IN (?,?)', [$user1['id'], $user2['id']]);
$db->query('DELETE FROM ly_admins WHERE id=?', [$adminId]);

// ---------- 自检 ----------
$leftOrders = $ids !== '' ? (int)$db->value("SELECT COUNT(*) FROM ly_orders WHERE id IN ($ids)") : 0;
check('测试订单已全部清理', $leftOrders === 0);
check('测试商品与库存已清理',
    (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE id IN (?,?,?)', [$pFree1, $pFree2, $pPaid]) === 0
    && (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id IN (?,?,?)', [$pFree1, $pFree2, $pPaid]) === 0);
check('后台保存的 0 元商品已清理', (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE name=?', [$TAG . '_后台零元']) === 0);
check('测试券与持券记录已清理',
    (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$couponId]) === 0
    && (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$couponId]) === 0);
check('测试用户余额流水已清理',
    (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE user_id IN (?,?)', [$user1['id'], $user2['id']]) === 0);
check('测试用户与管理员已清理',
    (int)$db->value('SELECT COUNT(*) FROM ly_users WHERE id IN (?,?)', [$user1['id'], $user2['id']]) === 0
    && (int)$db->value('SELECT COUNT(*) FROM ly_admins WHERE id=?', [$adminId]) === 0);

$snapOk = true;
foreach ($snap as $k => $v) {
    if ((string)Setting::get($k, '__none__') !== $v) {
        $snapOk = false;
        echo "    - 未还原: {$k} = " . Setting::get($k, '') . "（期望 {$v}）" . PHP_EOL;
    }
}
check('系统配置已还原至测试前快照', $snapOk);

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "0 元商品测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
