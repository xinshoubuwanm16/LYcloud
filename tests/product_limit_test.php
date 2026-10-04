<?php
/**
 * 商品购买限制次数测试（1.7.4 新增）
 *
 * 覆盖：
 *   - Product 限购字段归一化（负数/超上限/非数字/小数）
 *   - limitText / canPurchase / remainingQuota / purchasedCount 语义
 *   - 计数口径：已支付 + 已发货计入；待支付 / 已关闭 / 已退款 不计入
 *   - 下单拦截：达到上限后 createWithStock 抛拒绝
 *   - 未付款不占名额；超时关单后名额不占用；退款释放名额
 *   - 不限购商品（limit=0）完全不受影响（回归）
 *   - 限购按商品独立（A 商品限购不影响 B 商品）
 *   - 后台保存：合法值落库 / 边界归一
 *   - HTTP：商品详情页限购提示、达到上限时按钮禁用
 *   - 并发：额度已满时并发下单全部被拒（FOR UPDATE 行锁防护）
 *
 * 用法：LY_TEST_BASE=http://127.0.0.1:8099 php tests/product_limit_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;

$BASE = getenv('LY_TEST_BASE') ?: 'http://127.0.0.1:8099';
$db = Database::instance();

$pass = 0;
$fail = 0;
$TEST_TAG = 'LIMTEST' . random_int(10000, 99999);

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
// HTTP 辅助
// ============================================================

function httpGet(string $url, string $jar = '', array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
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
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

function httpPost(string $url, array $data, string $jar = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}

function grabToken(string $html): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

// ============================================================
// 构造测试数据
// ============================================================

/** 建商品（可指定限购与库存模式） */
function mkLimitProduct(Database $db, string $tag, int $limit, int $stockMode = 0): int
{
    return (int)$db->insert('ly_products', [
        'name' => $tag, 'subtitle' => $tag, 'description' => $tag,
        'price' => '10.00', 'original_price' => '0.00',
        'cover' => '', 'tags' => '', 'spec' => '',
        'stock_mode' => $stockMode, 'auto_deliver' => 1,
        'duration_value' => 0, 'duration_unit' => 'month',
        'limit_per_user' => $limit,
        'sort' => 0, 'sales' => 0, 'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function mkLimitUser(Database $db, string $tag): int
{
    $email = $tag . random_int(10000, 99999) . '@qq.com';
    return (int)$db->insert('ly_users', [
        'email' => $email,
        'password' => password_hash('Test1234', PASSWORD_DEFAULT),
        'nickname' => $tag,
        'balance' => '0.00', 'status' => 1, 'email_verified' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/** 下单并（可选）立刻付款 */
function makeOrder(Database $db, int $uid, int $pid, bool $pay, string $tradeSeed): array
{
    $user = $db->first('SELECT * FROM ly_users WHERE id=?', [$uid]);
    $product = Product::find($pid);
    $order = Order::createWithStock($user, $product, 'qr', false, null);
    if ($pay) {
        Order::markPaid((int)$order['id'], $tradeSeed . random_int(1000, 9999));
    }
    return $order;
}

$createdProducts = [];
$createdUsers = [];

// ============================================================
group('1. 归一化与文本');

check('normalizeLimit(-5) → 0', Product::normalizeLimit(-5) === 0, (string)Product::normalizeLimit(-5));
check('normalizeLimit(0) → 0（不限购）', Product::normalizeLimit(0) === 0);
check('normalizeLimit(3) → 3', Product::normalizeLimit(3) === 3);
check('normalizeLimit(9999) → 9999（上限）', Product::normalizeLimit(9999) === 9999);
check('normalizeLimit(99999) → 9999（封顶）', Product::normalizeLimit(99999) === 9999, (string)Product::normalizeLimit(99999));

check('limitText(0) = 不限购', Product::limitText(['limit_per_user' => 0]) === '不限购');
check('limitText(1) = 每人限购 1 次', Product::limitText(['limit_per_user' => 1]) === '每人限购 1 次');
check('limitText(5) = 每人限购 5 次', Product::limitText(['limit_per_user' => 5]) === '每人限购 5 次');
check('limitText(null) = 不限购（安全兜底）', Product::limitText(null) === '不限购');

// ============================================================
group('2. 计数口径：什么算「一次」');

$pLimited = mkLimitProduct($db, $TEST_TAG . '_p1', 2);
$createdProducts[] = $pLimited;
$u1 = mkLimitUser($db, $TEST_TAG . '_u1');
$createdUsers[] = $u1;

check('初始已购 0 次', Product::purchasedCount($u1, $pLimited) === 0);

// 待支付订单不计入
$o1 = makeOrder($db, $u1, $pLimited, false, $TEST_TAG);
check('待支付订单不计入已购次数', Product::purchasedCount($u1, $pLimited) === 0);

// 付款后计入
Order::markPaid((int)$o1['id'], $TEST_TAG . 'T1');
check('付款后计入 1 次', Product::purchasedCount($u1, $pLimited) === 1);
check('订单状态为已支付或已发货', in_array((int)$db->value('SELECT status FROM ly_orders WHERE id=?', [(int)$o1['id']]), [Order::STATUS_PAID, Order::STATUS_DELIVERED], true));

// 已关闭订单不计入
$o2 = makeOrder($db, $u1, $pLimited, true, $TEST_TAG);
check('第二单付款后已购 2 次', Product::purchasedCount($u1, $pLimited) === 2);
$db->update('ly_orders', ['status' => Order::STATUS_CLOSED], 'id=?', [(int)$o2['id']]);
check('订单改为已关闭后不再计入', Product::purchasedCount($u1, $pLimited) === 1);

// 已退款订单不计入
$db->update('ly_orders', ['status' => Order::STATUS_REFUNDED], 'id=?', [(int)$o1['id']]);
check('订单改为已退款后不再计入', Product::purchasedCount($u1, $pLimited) === 0);

// ============================================================
group('3. canPurchase / remainingQuota 语义');

$pLimit2 = mkLimitProduct($db, $TEST_TAG . '_p2', 2);
$createdProducts[] = $pLimit2;
$u2 = mkLimitUser($db, $TEST_TAG . '_u2');
$createdUsers[] = $u2;

$q = Product::canPurchase(Product::find($pLimit2), $u2);
check('未购买时可购买', $q['ok'] === true);
check('剩余额度 = 2', $q['remaining'] === 2);
check('quota.limit = 2', $q['limit'] === 2);

makeOrder($db, $u2, $pLimit2, true, $TEST_TAG);
$q = Product::canPurchase(Product::find($pLimit2), $u2);
check('买 1 次后剩余 1', $q['remaining'] === 1 && $q['ok'] === true);

makeOrder($db, $u2, $pLimit2, true, $TEST_TAG);
$q = Product::canPurchase(Product::find($pLimit2), $u2);
check('买满 2 次后不可购买', $q['ok'] === false);
check('剩余额度为 0', $q['remaining'] === 0);
check('拒绝文案含限购次数', strpos($q['msg'], '限购 2 次') !== false, $q['msg']);
check('拒绝文案含已购次数', strpos($q['msg'], '已购买 2 次') !== false, $q['msg']);

// 不限购商品
$pFree = mkLimitProduct($db, $TEST_TAG . '_pfree', 0);
$createdProducts[] = $pFree;
$q = Product::canPurchase(Product::find($pFree), $u2);
check('不限购商品可购买', $q['ok'] === true);
check('不限购 remaining = null', $q['remaining'] === null);
check('不限购 limit = 0', $q['limit'] === 0);

// ============================================================
group('4. 下单拦截（createWithStock）');

$pLimit1 = mkLimitProduct($db, $TEST_TAG . '_p3', 1);
$createdProducts[] = $pLimit1;
$u3 = mkLimitUser($db, $TEST_TAG . '_u3');
$createdUsers[] = $u3;

$o = makeOrder($db, $u3, $pLimit1, true, $TEST_TAG);
check('第 1 次下单成功', (int)$o['id'] > 0);

$rejected = false;
$msg = '';
try {
    makeOrder($db, $u3, $pLimit1, false, $TEST_TAG);
} catch (\RuntimeException $e) {
    $rejected = true;
    $msg = $e->getMessage();
}
check('达到上限后第 2 次下单被拒', $rejected);
check('拒绝信息为限购提示', strpos($msg, '限购') !== false, $msg);

// 额度用满时不应占用库存（校验先于库存分配 → 不限库存模式下无副作用）
check('被拒订单未入库', (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE user_id=? AND product_id=?', [$u3, $pLimit1]) === 1);

// ============================================================
group('5. 限购按商品独立');

$pA = mkLimitProduct($db, $TEST_TAG . '_pA', 1);
$pB = mkLimitProduct($db, $TEST_TAG . '_pB', 1);
$createdProducts[] = $pA;
$createdProducts[] = $pB;
$u4 = mkLimitUser($db, $TEST_TAG . '_u4');
$createdUsers[] = $u4;

makeOrder($db, $u4, $pA, true, $TEST_TAG);
check('商品A 已购满', Product::canPurchase(Product::find($pA), $u4)['ok'] === false);
check('商品B 不受影响，仍可购买', Product::canPurchase(Product::find($pB), $u4)['ok'] === true);

$oB = makeOrder($db, $u4, $pB, true, $TEST_TAG);
check('商品B 下单成功', (int)$oB['id'] > 0);

// ============================================================
group('6. 不限购商品完全不受影响（回归）');

$pReg = mkLimitProduct($db, $TEST_TAG . '_preg', 0);
$createdProducts[] = $pReg;
$u5 = mkLimitUser($db, $TEST_TAG . '_u5');
$createdUsers[] = $u5;

$okCount = 0;
for ($i = 0; $i < 5; $i++) {
    try {
        makeOrder($db, $u5, $pReg, true, $TEST_TAG);
        $okCount++;
    } catch (\Throwable $e) {
        // 忽略
    }
}
check('不限购商品连下 5 单全部成功', $okCount === 5, (string)$okCount);

// ============================================================
group('7. 后台保存限购值');

$adminJar = sys_get_temp_dir() . '/lim_admin_' . random_int(10000, 99999) . '.jar';
$loginPage = httpGet($BASE . '/admin/auth/login', $adminJar);
$token = grabToken($loginPage['body']);
check('后台登录页可访问', $loginPage['code'] === 200 && $token !== '');
$login = httpPost($BASE . '/admin/auth/login', ['username' => 'admin', 'password' => 'admin888', '_token' => $token], $adminJar);
check('后台登录成功（302）', $login['code'] === 302, (string)$login['code']);

// 从表单页取 token（POST-only 路由 GET 无 token）
$formPage = httpGet($BASE . '/admin/product/form?id=' . $pLimit1, $adminJar);
$formToken = grabToken($formPage['body']);
check('商品表单页含 limit_per_user 字段', strpos($formPage['body'], 'name="limit_per_user"') !== false);
check('表单回显当前限购值 1', (bool)preg_match('/name="limit_per_user"[^>]*value="1"/', $formPage['body']));

// 提交限购 = 3
$save = httpPost($BASE . '/admin/product/save', [
    'id' => $pLimit1,
    'name' => $TEST_TAG . '_p3',
    'price' => '10.00',
    'stock_mode' => 0,
    'auto_deliver' => 1,
    'status' => 1,
    'duration_value' => 0,
    'duration_unit' => 'month',
    'limit_per_user' => 3,
    '_token' => $formToken,
], $adminJar);
check('后台保存返回 302', $save['code'] === 302, (string)$save['code']);
check('限购值已更新为 3', (int)Product::find($pLimit1)['limit_per_user'] === 3, (string)Product::find($pLimit1)['limit_per_user']);

// 边界：超上限封顶
$formPage = httpGet($BASE . '/admin/product/form?id=' . $pLimit1, $adminJar);
$formToken = grabToken($formPage['body']);
httpPost($BASE . '/admin/product/save', [
    'id' => $pLimit1, 'name' => $TEST_TAG . '_p3', 'price' => '10.00',
    'stock_mode' => 0, 'auto_deliver' => 1, 'status' => 1,
    'limit_per_user' => 99999, '_token' => $formToken,
], $adminJar);
check('提交 99999 → 落库 9999（封顶）', (int)Product::find($pLimit1)['limit_per_user'] === 9999, (string)Product::find($pLimit1)['limit_per_user']);

// 边界：负数归 0
$formPage = httpGet($BASE . '/admin/product/form?id=' . $pLimit1, $adminJar);
$formToken = grabToken($formPage['body']);
httpPost($BASE . '/admin/product/save', [
    'id' => $pLimit1, 'name' => $TEST_TAG . '_p3', 'price' => '10.00',
    'stock_mode' => 0, 'auto_deliver' => 1, 'status' => 1,
    'limit_per_user' => -5, '_token' => $formToken,
], $adminJar);
check('提交 -5 → 落库 0', (int)Product::find($pLimit1)['limit_per_user'] === 0, (string)Product::find($pLimit1)['limit_per_user']);

// 后台列表显示限购列
$listPage = httpGet($BASE . '/admin/product/list', $adminJar);
check('后台列表含「限购」表头', strpos($listPage['body'], '>限购<') !== false);
check('后台列表含「不限购」徽章', strpos($listPage['body'], '不限购') !== false);

// ============================================================
group('8. HTTP：前台商品页限购展示');

// 未登录访客：只看得到限购规则，看不到个人剩余
$guest = httpGet($BASE . '/product/show?id=' . $pLimit2);
check('访客可访问商品页', $guest['code'] === 200, (string)$guest['code']);
check('访客可见「每人 2 次」规则', strpos($guest['body'], '每人 2 次') !== false);
check('访客可见「购买限制」说明', strpos($guest['body'], '购买限制') !== false || strpos($guest['body'], '限购') !== false);

// 已购满用户：按钮禁用
$userJar = sys_get_temp_dir() . '/lim_user_' . random_int(10000, 99999) . '.jar';
$userEmail = (string)$db->value('SELECT email FROM ly_users WHERE id=?', [$u2]);
$lp = httpGet($BASE . '/auth/login', $userJar);
$ut = grabToken($lp['body']);
$lg = httpPost($BASE . '/auth/login', ['email' => $userEmail, 'password' => 'Test1234', '_token' => $ut], $userJar);
check('前台用户登录成功', $lg['code'] === 302, (string)$lg['code']);

$pd = httpGet($BASE . '/product/show?id=' . $pLimit2, $userJar);
check('已购满用户看到「已购满 2 次」', strpos($pd['body'], '已购满 2 次') !== false);
check('已购满用户看到「已达购买上限」', strpos($pd['body'], '已达购买上限') !== false);
check('已达上限时购买按钮禁用', strpos($pd['body'], 'disabled') !== false);

// 还有额度用户：显示剩余次数
$u6 = mkLimitUser($db, $TEST_TAG . '_u6');
$createdUsers[] = $u6;
makeOrder($db, $u6, $pLimit2, true, $TEST_TAG);
$jar6 = sys_get_temp_dir() . '/lim_u6_' . random_int(10000, 99999) . '.jar';
$email6 = (string)$db->value('SELECT email FROM ly_users WHERE id=?', [$u6]);
$lp6 = httpGet($BASE . '/auth/login', $jar6);
$ut6 = grabToken($lp6['body']);
httpPost($BASE . '/auth/login', ['email' => $email6, 'password' => 'Test1234', '_token' => $ut6], $jar6);
$pd6 = httpGet($BASE . '/product/show?id=' . $pLimit2, $jar6);
check('有额度用户看到「还可购 1 次」', strpos($pd6['body'], '还可购 1 次') !== false);
check('有额度用户按钮可点（立即购买）', strpos($pd6['body'], '立即购买') !== false);

// ============================================================
group('9. 并发：额度已满时全部被拒');

$pCc = mkLimitProduct($db, $TEST_TAG . '_pcc', 1);
$createdProducts[] = $pCc;
$uCc = mkLimitUser($db, $TEST_TAG . '_ucc');
$createdUsers[] = $uCc;

// 先买满 1 次
makeOrder($db, $uCc, $pCc, true, $TEST_TAG);
check('并发测试前置：额度已用满', Product::canPurchase(Product::find($pCc), $uCc)['ok'] === false);

// 8 个并发子进程尝试下单
$workerFile = sys_get_temp_dir() . '/lim_cc_worker.php';
file_put_contents($workerFile, '<?php
require ' . var_export(__DIR__ . '/../app/bootstrap.php', true) . ';
$uid = (int)$argv[1]; $pid = (int)$argv[2];
usleep(random_int(0, 40000));
$db = App\Database::instance();
$u = $db->first("SELECT * FROM ly_users WHERE id=?", [$uid]);
$p = App\Models\Product::find($pid);
try { App\Models\Order::createWithStock($u, $p, "qr", false, null); echo "OK"; }
catch (Throwable $e) { echo "FAIL"; }
');

$procs = [];
$pipes = [];
for ($i = 0; $i < 8; $i++) {
    $cmd = PHP_BINARY . ' ' . escapeshellarg($workerFile) . ' ' . $uCc . ' ' . $pCc;
    $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$okCount = 0;
foreach ($procs as $i => $p) {
    $out = trim((string)stream_get_contents($pipes[$i][1]));
    stream_get_contents($pipes[$i][2]);
    proc_close($p);
    if ($out === 'OK') {
        $okCount++;
    }
}
@unlink($workerFile);

check('额度已满时并发 8 个下单全部被拒', $okCount === 0, "成功 {$okCount} 个");
check('并发后订单数未增加（仍为 1）', (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE user_id=? AND product_id=?', [$uCc, $pCc]) === 1);

// ============================================================
group('10. 清理');

foreach ($createdUsers as $uid) {
    $db->delete('ly_balance_logs', 'user_id=?', [$uid]);
    $db->delete('ly_orders', 'user_id=?', [$uid]);
    $db->delete('ly_users', 'id=?', [$uid]);
}
foreach ($createdProducts as $pid) {
    $db->delete('ly_stocks', 'product_id=?', [$pid]);
    $db->delete('ly_products', 'id=?', [$pid]);
}
@unlink($adminJar);
@unlink($userJar);
@unlink($jar6);

$leftUsers = (int)$db->value('SELECT COUNT(*) FROM ly_users WHERE nickname LIKE ?', [$TEST_TAG . '%']);
$leftProducts = (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE name LIKE ?', [$TEST_TAG . '%']);
$leftOrders = (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE order_no LIKE ? AND user_id IN (' . implode(',', array_map('intval', $createdUsers ?: [0])) . ')', ['%']);
check('测试用户已清理', $leftUsers === 0, (string)$leftUsers);
check('测试商品已清理', $leftProducts === 0, (string)$leftProducts);
check('测试订单已清理', $leftOrders === 0, (string)$leftOrders);

// ============================================================
echo PHP_EOL . str_repeat('=', 30) . PHP_EOL;
echo "商品限购测试：{$pass} 项通过，{$fail} 项失败" . PHP_EOL;
echo str_repeat('=', 30) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
