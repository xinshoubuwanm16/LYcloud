<?php
/**
 * 订单超时自动关闭测试（1.6.0）
 *
 * 覆盖：
 *  - cron/tick 端点：密钥缺失 403 / 错误密钥 403 / 正确密钥执行关单 / cron_key 惰性生成
 *  - 惰性触发：访问首页即清理超时订单
 *  - 完整关单：释放库存 + 退回优惠券 + 退回纯余额抵扣款（closeWithRefund 路径）
 *  - 未超时订单与已支付订单不受影响
 *  - markPaid 恢复兜底：超时关闭后支付回调到达 → 重新分配库存发货；无库存 → 退款至余额
 *  - markPaid 幂等：已处理订单重复回调不再发货
 *
 * 运行：需先启动本地 HTTP 服务（php -S 127.0.0.1:8099 -t .），
 *       LY_TEST_BASE=http://127.0.0.1:8099 php tests/order_expire_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;

$pass = 0; $fail = 0;
$chk = function (string $name, bool $cond, string $extra = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; echo "  [FAIL] $name" . ($extra !== '' ? "  -- $extra" : '') . "\n"; }
};

$BASE = rtrim((string)getenv('LY_TEST_BASE'), '/');
if ($BASE === '') {
    echo "请设置 LY_TEST_BASE（例如 http://127.0.0.1:8099）\n";
    exit(1);
}

function httpGet(string $url, string $jar = ''): string
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false];
    if ($jar !== '') {
        $opt[CURLOPT_COOKIEJAR] = $jar;
        $opt[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    curl_close($ch);
    return (string)$body;
}

function httpCode(string $url): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/* ---------- 前置：HTTP 管理端保存 order_expire_min=5（防单进程 Setting 缓存污染） ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$USER = 'exp_admin_' . substr(md5(uniqid('', true)), 0, 8);
$PASS = 'Exp' . random_int(100000, 999999);
$jar = sys_get_temp_dir() . "/exp_" . uniqid() . ".jar";
$adminId = Admin::create($USER, $PASS, '超时关单回归');
$chk('创建临时管理员', $adminId > 0);

$loginPage = httpGet($BASE . '/index.php?r=admin/auth/login', $jar);
preg_match('/name="_token" value="([^"]+)"/', $loginPage, $m);
$token = $m[1] ?? '';
httpPost($BASE . '/index.php?r=admin/auth/login', ['_token' => $token, 'username' => $USER, 'password' => $PASS], $jar);

function httpPost(string $url, array $data, string $jar): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return (string)$body;
}

$setPage = httpGet($BASE . '/index.php?r=admin/setting/index', $jar);
preg_match('/name="_token" value="([^"]+)"/', $setPage, $m);
$setToken = $m[1] ?? '';
$chk('设置页取到 CSRF', $setToken !== '');

/** 保存设置并输出诊断（响应码 + 重定向位置） */
function saveSetting(string $BASE, string $jar, string $token, string $value): void
{
    $ch = curl_init($BASE . '/index.php?r=admin/setting/save');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['_token' => $token, 'order_expire_min' => $value]),
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    echo "      [save order_expire_min={$value} -> {$code} loc={$loc}]\n";
}

saveSetting($BASE, $jar, $setToken, '5');
// 注意：save 需要完整表单字段？SettingController 按提交键保存，未提交键不动 —— 仅提交本键安全
$chk('order_expire_min 已保存为 5', Setting::get('order_expire_min', '') === '5', Setting::get('order_expire_min', '(空)'));
// php -S 单进程：同一进程的 Setting 缓存已被 HTTP 保存刷新，HTTP 端读到的即是 5

/* ---------- 测试数据：用户/商品/库存/券/订单 ---------- */
$db = Database::instance();
$uEmail = 'exp_' . substr(md5(uniqid('', true)), 0, 8) . '@qq.com';
$uid = User::create($uEmail, 'Pass' . random_int(100000, 999999), '超时测试', true);
$chk('创建测试用户', $uid > 0);

$pid = (int)$db->insert('ly_products', [
    'name' => '超时关单测试商品', 'description' => '', 'price' => '10.00',
    'stock_mode' => 1, 'auto_deliver' => 1, 'status' => 1, 'sales' => 0,
    'created_at' => date('Y-m-d H:i:s'),
]);
$mkStock = function (int $productId): int {
    return (int)Database::instance()->insert('ly_stocks', [
        'product_id' => $productId,
        'panel_url' => 'http://panel.example/' . uniqid(),
        'panel_user' => 'u' . random_int(10000, 99999),
        'panel_pass' => 'p' . random_int(10000, 99999),
        'status' => 0,
        'order_id' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
};
$mkOrder = function (array $over = []) use ($db, $uid, $pid): int {
    $row = array_merge([
        'order_no' => 'EXP' . date('ymdHis') . random_int(1000, 9999),
        'user_id' => $uid,
        'product_id' => $pid,
        'product_name' => '超时关单测试商品',
        'stock_id' => 0,
        'amount' => '10.00', 'pay_amount' => '10.00', 'balance_paid' => '0.00',
        'coupon_id' => 0, 'coupon_discount' => '0.00', 'quantity' => 1,
        'pay_channel' => 'qr', 'trade_no' => '', 'status' => 0,
        'client_ip' => '127.0.0.1',
        'created_at' => date('Y-m-d H:i:s'),
    ], $over);
    return (int)$db->insert('ly_orders', $row);
};

/* ---------- 一、cron/tick 端点 ---------- */
echo "== 一、cron/tick 计划任务端点 ==\n";
$codeNoKey = httpCode($BASE . '/index.php?r=cron/tick');
$chk('无密钥访问 403', $codeNoKey === 403, "实际 {$codeNoKey}");
$cronKey = (string)Setting::get('cron_key');
$chk('cron_key 已自动生成', $cronKey !== '');

$codeBadKey = httpCode($BASE . '/index.php?r=cron/tick&key=wrong_key_123');
$chk('错误密钥 403', $codeBadKey === 403, "实际 {$codeBadKey}");

$resp = httpGet($BASE . '/index.php?r=cron/tick&key=' . urlencode($cronKey));
$j = json_decode($resp, true);
$chk('正确密钥返回 ok=true', is_array($j) && ($j['ok'] ?? false) === true, substr($resp, 0, 120));
$chk('响应含 minutes=5', is_array($j) && (int)($j['minutes'] ?? 0) === 5, json_encode($j, JSON_UNESCAPED_UNICODE));

/* ---------- 二、惰性触发 + 完整关单（库存/券/余额） ---------- */
echo "== 二、超时订单自动关闭（首页触发）==\n";

// 用户余额先充值（模拟纯余额抵扣单在关单时退款）
Database::instance()->update('ly_users', ['balance' => '0.00'], 'id=?', [$uid]);
\App\Models\BalanceLog::credit($uid, '4.00', \App\Models\BalanceLog::TYPE_REDEEM, 0, '测试充值');

$stockA = $mkStock($pid);
$ucA = (int)$db->insert('ly_user_coupons', [
    'coupon_id' => 0, 'user_id' => $uid, 'source' => 'admin', 'status' => 1,
    'order_id' => 0, 'used_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
]);
$oidA = $mkOrder([
    'stock_id' => $stockA,
    'coupon_id' => $ucA, // closeWithRefund 按此键触发退券（revertByOrder 按 order_id 回查）
    'coupon_discount' => '2.00',
    'balance_paid' => '4.00',
    'pay_channel' => 'balance',   // 纯余额单：关单应退款 4.00
    'pay_amount' => '0.00',
    'amount' => '6.00',
    'created_at' => date('Y-m-d H:i:s', time() - 360), // 6 分钟前（超时）
]);
// 库存标记已售（模拟下单占库）
$db->update('ly_stocks', ['status' => 1, 'order_id' => $oidA, 'sold_at' => date('Y-m-d H:i:s')], 'id=?', [$stockA]);
// 券核销绑定订单（模拟下单核销）
$db->update('ly_user_coupons', ['status' => 1, 'order_id' => $oidA, 'used_at' => date('Y-m-d H:i:s')], 'id=?', [$ucA]);

// 未超时订单
$oidB = $mkOrder(['created_at' => date('Y-m-d H:i:s', time() - 120)]); // 2 分钟前
// 已支付订单（超时时间之前创建但已支付，绝不能被关）
$oidC = $mkOrder(['status' => 1, 'trade_no' => 'TRD_PAID_1', 'paid_at' => date('Y-m-d H:i:s', time() - 300)]);

// 触发：访问首页（惰性清理）
httpGet($BASE . '/index.php');

$stA = (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oidA]);
$chk('超时订单已关闭', $stA === Order::STATUS_CLOSED, "实际 status={$stA}");
$chk('库存已释放', (int)$db->value('SELECT status FROM ly_stocks WHERE id=?', [$stockA]) === 0);
$chk('订单不再占用库存', (int)$db->value('SELECT stock_id FROM ly_orders WHERE id=?', [$oidA]) === 0);
$uc = $db->first('SELECT status, order_id FROM ly_user_coupons WHERE id=?', [$ucA]);
$chk('优惠券已退回', $uc && (int)$uc['status'] === 0 && (int)$uc['order_id'] === 0, json_encode($uc, JSON_UNESCAPED_UNICODE));
$bal = (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uid]);
$chk('纯余额抵扣款已退还（4 充值 + 4 退款 = 8.00）', bccomp($bal, '8.00', 2) === 0, "余额 {$bal}");
$chk('退款示写入流水', (int)$db->value("SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=? AND type='refund'", [$uid]) >= 1);

$stB = (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oidB]);
$chk('未超时订单不受影响', $stB === Order::STATUS_PENDING, "实际 status={$stB}");
$stC = (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oidC]);
$chk('已支付订单不被关闭', $stC === Order::STATUS_PAID, "实际 status={$stC}");

/* ---------- 三、cron 兜底关单（页面无人访问场景） ---------- */
echo "== 三、cron 兜底关单 ==\n";
$oidD = $mkOrder(['created_at' => date('Y-m-d H:i:s', time() - 360)]);
$resp = httpGet($BASE . '/index.php?r=cron/tick&key=' . urlencode($cronKey));
$j = json_decode($resp, true);
$stD = (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oidD]);
$chk('cron 触发后超时订单已关闭', $stD === Order::STATUS_CLOSED && is_array($j) && ($j['ok'] ?? false), "status={$stD} resp=" . substr($resp, 0, 120));

/* ---------- 四、markPaid 恢复兜底 ---------- */
echo "== 四、超时关闭后支付回调到达 ==\n";

// 4.1 有库存：恢复订单并发货
$stockE = $mkStock($pid);
$oidE = $mkOrder([
    'stock_id' => 0,
    'status' => Order::STATUS_CLOSED,          // 已被超时关闭
    'trade_no' => '',                          // 未支付过
    'pay_channel' => 'qr',
]);
$db->update('ly_orders', ['stock_id' => 0], 'id=?', [$oidE]);
$done = Order::markPaid($oidE, 'TRD_RECOVER_1');
$stE = (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oidE]);
$stockBind = (int)$db->value('SELECT stock_id FROM ly_orders WHERE id=?', [$oidE]);
$chk('恢复路径：markPaid 返回 true', $done === true);
$chk('恢复路径：订单已发货', $stE === Order::STATUS_DELIVERED, "实际 status={$stE}");
$bindRow = $db->first('SELECT status, order_id FROM ly_stocks WHERE id=?', [$stockBind]);
$chk('恢复路径：重新分配库存并绑定订单', $stockBind > 0 && $bindRow && (int)$bindRow['status'] === 1 && (int)$bindRow['order_id'] === $oidE,
    "stock_id={$stockBind} row=" . json_encode($bindRow, JSON_UNESCAPED_UNICODE));

// 4.2 无库存：全额退款至余额，订单标记已退款
$db->update('ly_stocks', ['status' => 1, 'order_id' => 999999], 'status=0 AND product_id=?', [$pid]); // 占光库存
$balBefore = (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uid]);
$oidF = $mkOrder([
    'status' => Order::STATUS_CLOSED,
    'trade_no' => '',
    'pay_channel' => 'qr',
    'pay_amount' => '10.00',
]);
$done = Order::markPaid($oidF, 'TRD_RECOVER_2');
$stF = (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oidF]);
$balAfter = (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uid]);
$expectBal = bcadd($balBefore, '10.00', 2);
$chk('无库存兜底：markPaid 返回 true', $done === true);
$chk('无库存兜底：订单标记已退款', $stF === Order::STATUS_REFUNDED, "实际 status={$stF}");
$chk('无库存兜底：实付金额已退到余额', bccomp($balAfter, $expectBal, 2) === 0, "{$balBefore} + 10.00 => {$balAfter}");

// 恢复测试环境库存
$db->update('ly_stocks', ['status' => 0, 'order_id' => 0], 'order_id=999999 AND product_id=?', [$pid]);

// 4.3 幂等：已发货订单重复回调不重复处理
$doneAgain = Order::markPaid($oidE, 'TRD_RECOVER_1_DUP');
$chk('幂等：已处理订单重复回调返回 false', $doneAgain === false);

/* ---------- 五、autoCloseExpired 开关（0 = 不自动关闭） ---------- */
echo "== 五、分钟数设 0 时不动任何订单 ==\n";
saveSetting($BASE, $jar, $setToken, '0');
$dbVal = (string)Database::instance()->value("SELECT v FROM ly_settings WHERE `k`='order_expire_min'");
$chk('order_expire_min 已保存为 0', $dbVal === '0', $dbVal);
$oidG = $mkOrder(['created_at' => date('Y-m-d H:i:s', time() - 3600)]);
$n = Order::autoCloseExpired(0);
$stG = (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oidG]);
$chk('minutes=0 时不关单', $n === 0 && $stG === Order::STATUS_PENDING, "closed={$n} status={$stG}");
// 恢复 5 分钟
saveSetting($BASE, $jar, $setToken, '5');
$dbVal = (string)Database::instance()->value("SELECT v FROM ly_settings WHERE `k`='order_expire_min'");
$chk('order_expire_min 已恢复为 5', $dbVal === '5', $dbVal);

/* ---------- 清理 ---------- */
$ids = array_filter([$oidA, $oidB, $oidC, $oidD, $oidE, $oidF, $oidG]);
$ph = implode(',', array_fill(0, count($ids), '?'));
$db->delete('ly_orders', "id IN ($ph)", array_values($ids));
$db->delete('ly_stocks', 'product_id=?', [$pid]);
$db->delete('ly_products', 'id=?', [$pid]);
$db->delete('ly_user_coupons', 'user_id=?', [$uid]);
$db->delete('ly_balance_logs', 'user_id=?', [$uid]);
$db->delete('ly_users', 'id=?', [$uid]);
$db->delete('ly_admins', 'id=?', [$adminId]);

echo "\n==== 订单超时自动关闭测试: $pass 通过 / $fail 失败 ====\n";
exit($fail > 0 ? 1 : 0);
