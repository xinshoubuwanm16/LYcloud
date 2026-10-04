<?php
/**
 * 商品有效期体系测试（1.7.0）
 *
 * 覆盖：
 *  - 商品有效期配置：后台保存（value/unit 落库）、非法单位归一、0=永久
 *  - Product::expireAt 起算单元（+1 month 日历语义、0 返回 null）
 *  - 支付写入 expire_at：网关路径（markPaid）与纯余额路径（createWithStock）
 *  - 到期邮件提醒 remindDue：SMTP 未配置不发送不标记、mock SMTP 发送成功并标记、
 *    幂等（已提醒不再发）、已过期订单不再补发、mock 收信内容含订单号与到期日
 *  - 后台到期管理：7 天内到期列表、已到期列表、Dashboard 警示条、标记已删机流转
 *  - 用户侧：订单列表渲染「剩余 N 天」、已到期红标
 *  - 弹窗公告（1.7.0 改版）：首页渲染 announceModal、横条已移除、多行 nl2br、XSS 转义、
 *    关闭记忆脚本、公告更新 hash 变化
 *
 * 运行：需先启动本地 HTTP 服务（php -S 127.0.0.1:8099 -t .），
 *       LY_TEST_BASE=http://127.0.0.1:8099 php tests/expire_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
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

$db = Database::instance();
$dbVal = function (string $sql, array $params = []) use ($db) {
    return $db->value($sql, $params);
};

/* ==================== mock SMTP（抄 wire 测试：proc_open + 明文 TCP 收信） ==================== */
$smtpPort = 4100 + (getmypid() % 70);
$smtpDump = sys_get_temp_dir() . "/expire_mail_{$smtpPort}.txt";
$smtpReady = sys_get_temp_dir() . "/expire_ready_{$smtpPort}.flag";
$smtpServerFile = sys_get_temp_dir() . "/expire_mock_{$smtpPort}.php";
@unlink($smtpDump); @unlink($smtpReady);
file_put_contents($smtpServerFile, <<<'PHPEOF'
<?php
$port = (int)$argv[1]; $dump = $argv[2]; $ready = $argv[3];
$srv = @stream_socket_server("tcp://127.0.0.1:$port", $en, $es, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
if (!$srv) exit(1);
file_put_contents($ready, '1');
$fp = @stream_socket_accept($srv, 60);
if (!$fp) exit(1);
fwrite($fp, "220 mock ESMTP ready\r\n");
$authStep = 0; $phase = 'cmd'; $raw = ''; $buf = '';
while (true) {
    $chunk = @fread($fp, 65536);
    if ($chunk === '' || $chunk === false) {
        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out'])) continue;
        break;
    }
    $buf .= $chunk;
    if ($phase === 'cmd') {
        while (($pos = strpos($buf, "\r\n")) !== false) {
            $line = substr($buf, 0, $pos); $buf = substr($buf, $pos + 2);
            $cmd = strtoupper(trim($line));
            if (str_starts_with($cmd, 'EHLO')) fwrite($fp, "250-mock\r\n250-AUTH LOGIN\r\n250 OK\r\n");
            elseif (str_starts_with($cmd, 'AUTH LOGIN')) { $authStep = 1; fwrite($fp, "334 VXNlcm5hbWU6\r\n"); }
            elseif ($authStep === 1) { $authStep = 2; fwrite($fp, "334 UGFzc3dvcmQ6\r\n"); }
            elseif ($authStep === 2) { $authStep = 0; fwrite($fp, "235 ok\r\n"); }
            elseif (str_starts_with($cmd, 'MAIL ') || str_starts_with($cmd, 'RCPT ')) fwrite($fp, "250 OK\r\n");
            elseif (str_starts_with($cmd, 'DATA')) { $phase = 'data'; fwrite($fp, "354 go\r\n"); break; }
            elseif (str_starts_with($cmd, 'QUIT')) { fwrite($fp, "221 bye\r\n"); break 2; }
            else fwrite($fp, "250 OK\r\n");
        }
    } else {
        $raw .= $buf; $buf = '';
        $end = strpos($raw, "\r\n.\r\n");
        if ($end !== false) {
            file_put_contents($dump, substr($raw, 0, $end));
            fwrite($fp, "250 queued OK\r\n");
            break;
        }
    }
}
PHPEOF);

$smtpProc = proc_open(
    PHP_BINARY . ' ' . escapeshellarg($smtpServerFile) . ' ' . $smtpPort . ' ' . escapeshellarg($smtpDump) . ' ' . escapeshellarg($smtpReady),
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $smtpPipes
);
stream_set_blocking($smtpPipes[2], false);
for ($i = 0; $i < 40 && !file_exists($smtpReady); $i++) {
    usleep(50000);
}
$mockReady = file_exists($smtpReady);

/** 配置 CLI 进程内的 SMTP 指向 mock（MailService 同进程读同缓存） */
$setMockSmtp = function () use ($smtpPort): void {
    Setting::set('smtp_enabled', '1');
    Setting::set('smtp_host', '127.0.0.1');
    Setting::set('smtp_port', (string)$smtpPort);
    Setting::set('smtp_encryption', 'none');
    Setting::set('smtp_username', 'tester@qq.com');
    Setting::set('smtp_password', 'authcode');
    Setting::set('smtp_from_email', 'tester@qq.com');
    Setting::set('smtp_from_name', 'LY云计算测试');
};
$clearSmtp = function (): void {
    Setting::set('smtp_enabled', '0');
};

/* ==================== 前置：HTTP 管理端登录 ==================== */
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$USER = 'exp_admin_' . substr(md5(uniqid('', true)), 0, 8);
$PASS = 'Exp' . random_int(100000, 999999);
$jar  = sys_get_temp_dir() . "/expire_" . uniqid() . ".jar";
$adminId = Admin::create($USER, $PASS, '到期测试');

$loginPage = httpGet($BASE . '/index.php?r=admin/auth/login', $jar);
preg_match('/name="_token" value="([^"]+)"/', $loginPage, $m);
httpPost($BASE . '/index.php?r=admin/auth/login', ['_token' => $m[1] ?? '', 'username' => $USER, 'password' => $PASS], $jar);

/** 管理端 POST（token 统一从设置页抓取——CSRF 是会话级，跨表单通用） */
$adminPost = function (string $route, array $kv) use ($BASE, $jar): string {
    $page = httpGet($BASE . '/index.php?r=admin/setting/index', $jar);
    preg_match('/name="_token" value="([^"]+)"/', $page, $m);
    $kv['_token'] = $m[1] ?? '';
    return httpPost($BASE . '/index.php?r=' . $route, $kv, $jar);
};

/* ==================== 第一节：商品有效期配置 ==================== */
echo "\n[1] 商品有效期配置\n";
$pid = (int)$db->insert('ly_products', [
    'name' => '有效期测试商品', 'description' => '', 'price' => '20.00',
    'stock_mode' => 1, 'auto_deliver' => 1, 'sort' => 0, 'sales' => 0, 'status' => 1,
    'duration_value' => 0, 'duration_unit' => 'month',
    'created_at' => date('Y-m-d H:i:s'),
]);
$adminPost('admin/product/save', [
    'id' => $pid, 'name' => '有效期测试商品', 'price' => '20.00', 'status' => '1',
    'duration_value' => '1', 'duration_unit' => 'month',
]);
$row = $db->first('SELECT duration_value, duration_unit FROM ly_products WHERE id=?', [$pid]);
$chk('保存 1 个月有效期落库', $row && (int)$row['duration_value'] === 1 && $row['duration_unit'] === 'month',
    json_encode($row, JSON_UNESCAPED_UNICODE));

$adminPost('admin/product/save', [
    'id' => $pid, 'name' => '有效期测试商品', 'price' => '20.00', 'status' => '1',
    'duration_value' => '3', 'duration_unit' => 'hack',
]);
$row = $db->first('SELECT duration_value, duration_unit FROM ly_products WHERE id=?', [$pid]);
$chk('非法单位归一为 month', $row && $row['duration_unit'] === 'month' && (int)$row['duration_value'] === 3);

$adminPost('admin/product/save', [
    'id' => $pid, 'name' => '有效期测试商品', 'price' => '20.00', 'status' => '1',
    'duration_value' => '0', 'duration_unit' => 'day',
]);
$row = $db->first('SELECT duration_value FROM ly_products WHERE id=?', [$pid]);
$chk('0 = 永久有效', $row && (int)$row['duration_value'] === 0);

/* ==================== 第二节：expireAt 起算单元 ==================== */
echo "\n[2] expireAt 起算\n";
$p1m = ['duration_value' => 1, 'duration_unit' => 'month'];
$chk('+1 个月日历语义（2026-01-31 → 2026-03-03）',
    Product::expireAt($p1m, '2026-01-31 10:00:00') === date('Y-m-d H:i:s', strtotime('2026-01-31 10:00:00 +1 month'))
    && Product::expireAt($p1m, '2026-01-31 10:00:00') === '2026-03-03 10:00:00',
    (string)Product::expireAt($p1m, '2026-01-31 10:00:00'));
$chk('7 天（2026-03-01 → 2026-03-08）',
    Product::expireAt(['duration_value' => 7, 'duration_unit' => 'day'], '2026-03-01 00:00:00') === '2026-03-08 00:00:00');
$chk('1 年（闰年边界 2028-02-29 → 2029-03-01）',
    Product::expireAt(['duration_value' => 1, 'duration_unit' => 'year'], '2028-02-29 12:00:00') === '2029-03-01 12:00:00',
    (string)Product::expireAt(['duration_value' => 1, 'duration_unit' => 'year'], '2028-02-29 12:00:00'));
$chk('0 = 永久（null）', Product::expireAt(['duration_value' => 0, 'duration_unit' => 'day'], '2026-01-01 00:00:00') === null);
$chk('非法单位归一 month', Product::expireAt(['duration_value' => 1, 'duration_unit' => 'evil'], '2026-01-31 10:00:00') === '2026-03-03 10:00:00');

/* ==================== 测试数据：用户 / 库存 / 订单 ==================== */
$uEmail = 'expire_' . substr(md5(uniqid('', true)), 0, 8) . '@qq.com';
$uid = User::create($uEmail, 'Pass' . random_int(100000, 999999), '到期测试', true);
$mkStock = function (int $productId): int {
    return (int)Database::instance()->insert('ly_stocks', [
        'product_id' => $productId,
        'panel_url' => 'http://panel.example/' . uniqid(),
        'panel_user' => 'u' . random_int(10000, 99999),
        'panel_pass' => 'p' . random_int(10000, 99999),
        'status' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
};
/** 直插待支付订单（已占库存） */
$mkOrder = function (int $productId, int $stockId, string $suffix) use ($db, $uid): int {
    return (int)$db->insert('ly_orders', [
        'order_no' => 'LYEXP' . strtoupper(substr(md5(uniqid('', true)), 0, 12)) . $suffix,
        'user_id' => $uid, 'product_id' => $productId,
        'product_name' => '有效期测试商品',
        'stock_id' => $stockId, 'amount' => '20.00', 'pay_amount' => '20.00',
        'balance_paid' => '0.00', 'quantity' => 1, 'pay_channel' => 'qr',
        'status' => 0, 'created_at' => date('Y-m-d H:i:s'),
    ]);
};

// 商品恢复为 1 个月有效期
$db->update('ly_products', ['duration_value' => 1, 'duration_unit' => 'month'], 'id=?', [$pid]);

/* ---------- 第三节：网关支付路径 markPaid ---------- */
echo "\n[3] 支付起算 expire_at\n";
$stockA = $mkStock($pid);
$oidA = $mkOrder($pid, $stockA, 'A');
Order::markPaid($oidA, 'TRADE_EXP_A');
$ordA = $db->first('SELECT paid_at, expire_at, status FROM ly_orders WHERE id=?', [$oidA]);
$expectA = Product::expireAt($p1m, (string)$ordA['paid_at']);
$chk('网关支付后 expire_at = paid_at + 1 个月', $expectA !== null && $ordA['expire_at'] === $expectA,
    "paid_at={$ordA['paid_at']} expire_at={$ordA['expire_at']} expect={$expectA}");
$chk('支付后订单已发货', (int)$ordA['status'] === Order::STATUS_DELIVERED);

/* ---------- 纯余额路径 createWithStock ---------- */
$db->query('UPDATE ly_users SET balance = ? WHERE id = ?', ['50.00', $uid]);
$userRow = User::find($uid);
$stockB = $mkStock($pid);
$created = Order::createWithStock($userRow, Product::find($pid), 'balance', true);
$oidB = (int)($created['id'] ?? 0); // createWithStock 返回订单完整行
$ordB = $oidB > 0 ? $db->first('SELECT paid_at, expire_at, status FROM ly_orders WHERE id=?', [$oidB]) : null;
$expectB = $ordB ? Product::expireAt($p1m, (string)$ordB['paid_at']) : null;
$chk('纯余额支付后 expire_at = paid_at + 1 个月', $ordB && $expectB !== null && $ordB['expire_at'] === $expectB,
    json_encode($ordB ?? [], JSON_UNESCAPED_UNICODE));

// 网关路径商品改 0 有效期 → expire_at 应为 NULL
$db->update('ly_products', ['duration_value' => 0], 'id=?', [$pid]);
$stockC = $mkStock($pid);
$oidC = $mkOrder($pid, $stockC, 'C');
Order::markPaid($oidC, 'TRADE_EXP_C');
$ordC = $db->first('SELECT expire_at FROM ly_orders WHERE id=?', [$oidC]);
$chk('永久商品支付后 expire_at 为 NULL', $ordC && $ordC['expire_at'] === null);
// 恢复 1 个月
$db->update('ly_products', ['duration_value' => 1], 'id=?', [$pid]);

/* ==================== 第四节：到期邮件提醒 remindDue ==================== */
echo "\n[4] 到期邮件提醒\n";
// 造一单 3 天后到期、未提醒
$stockD = $mkStock($pid);
$oidD = $mkOrder($pid, $stockD, 'D');
$expireSoon = date('Y-m-d H:i:s', strtotime('+3 day'));
$db->update('ly_orders', ['status' => Order::STATUS_DELIVERED, 'paid_at' => date('Y-m-d H:i:s', strtotime('-27 day')), 'expire_at' => $expireSoon], 'id=?', [$oidD]);
// 造一单已过期
$stockE = $mkStock($pid);
$oidE = $mkOrder($pid, $stockE, 'E');
$db->update('ly_orders', ['status' => Order::STATUS_DELIVERED, 'paid_at' => date('Y-m-d H:i:s', strtotime('-40 day')), 'expire_at' => date('Y-m-d H:i:s', strtotime('-1 day'))], 'id=?', [$oidE]);

$clearSmtp();
$chk('SMTP 未配置时 remindDue 返回 0', Order::remindDue(7) === 0);
$chk('未配置时不标记提醒时间', (int)$dbVal('SELECT COUNT(*) FROM ly_orders WHERE id=? AND reminded_at IS NULL', [$oidD]) === 1);

if (!$mockReady) {
    echo "  [SKIP] mock SMTP 未就绪，跳过发送断言\n";
} else {
    $setMockSmtp();
    $sent = Order::remindDue(7);
    $chk('mock SMTP 下 remindDue 发送 1 封', $sent === 1, "sent={$sent} err=" . \App\Mail\MailService::lastError());
    $chk('发送后标记 reminded_at', (int)$dbVal('SELECT COUNT(*) FROM ly_orders WHERE id=? AND reminded_at IS NOT NULL', [$oidD]) === 1);
    $chk('幂等：再扫不再发送', Order::remindDue(7) === 0);
    $chk('已过期订单不在提醒窗口', (int)$dbVal('SELECT COUNT(*) FROM ly_orders WHERE id=? AND reminded_at IS NULL', [$oidE]) === 1);

    $mail = (string)(is_file($smtpDump) ? file_get_contents($smtpDump) : '');
    $chk('邮件收件人为买家', stripos($mail, $uEmail) !== false, substr($mail, 0, 200));
    // 主题 RFC2047 + 正文 base64：解码后再搜业务内容
    $decoded = (string)preg_replace_callback(
        '/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/i',
        fn($m2) => base64_decode($m2[1]),
        $mail
    );
    foreach (preg_split('/\r\n/', $mail) ?: [] as $line) {
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $line) && strlen($line) > 20) {
            $d = base64_decode($line, true);
            if ($d !== false) {
                // 无缝拼接：base64 折行会把订单号等内容切断，行间不能插分隔符
                $decoded .= $d;
            }
        }
    }
    $ordNoD = (string)$dbVal('SELECT order_no FROM ly_orders WHERE id=?', [$oidD]);
    $chk('解码后主题含到期日期', strpos($decoded, substr($expireSoon, 0, 10)) !== false);
    $chk('解码后内容含订单号', strpos($decoded, $ordNoD) !== false);
    $chk('解码后内容含备份提醒文案', strpos($decoded, '备份') !== false);
    $clearSmtp();
}

/* ==================== 第五节：后台到期管理 ==================== */
echo "\n[5] 后台到期管理\n";
$expirePage = httpGet($BASE . '/index.php?r=admin/expire/list&kind=due', $jar);
$noD = (string)$dbVal('SELECT order_no FROM ly_orders WHERE id=?', [$oidD]);
$chk('7 天内到期列表出现测试订单', $noD !== '' && strpos($expirePage, $noD) !== false);
$expiredPage = httpGet($BASE . '/index.php?r=admin/expire/list&kind=expired', $jar);
$noE = (string)$dbVal('SELECT order_no FROM ly_orders WHERE id=?', [$oidE]);
$chk('已到期列表出现过期订单', $noE !== '' && strpos($expiredPage, $noE) !== false);

$dash = httpGet($BASE . '/index.php?r=admin/index', $jar);
$chk('控制台出现到期警示条', strpos($dash, '到期管理') !== false && (strpos($dash, '已到期') !== false || strpos($dash, '7 天内到期') !== false));

// 标记已删机：oidE（已过期单）
$adminPost('admin/expire/dispose', ['id' => $oidE, 'kind' => 'expired']);
$disposed = $db->first('SELECT disposed_at FROM ly_orders WHERE id=?', [$oidE]);
$chk('标记已删机落库', $disposed && $disposed['disposed_at'] !== null);
$expiredPage2 = httpGet($BASE . '/index.php?r=admin/expire/list&kind=expired', $jar);
$chk('已删机订单移出待办', strpos($expiredPage2, $noE) === false);
$disposedPage = httpGet($BASE . '/index.php?r=admin/expire/list&kind=disposed', $jar);
$chk('已删机列表可见', strpos($disposedPage, $noE) !== false);

/* ==================== 第六节：用户侧展示 ==================== */
echo "\n[6] 用户侧展示\n";
// HTTP 用户登录（先重置为已知密码）
$uJar = sys_get_temp_dir() . "/expire_u_" . uniqid() . ".jar";
$pwd = 'Exp' . random_int(100000, 999999);
$db->query('UPDATE ly_users SET password = ? WHERE id = ?', [\App\Auth::hash($pwd), $uid]);
$loginPage = httpGet($BASE . '/index.php?r=auth/login', $uJar);
preg_match('/name="_token" value="([^"]+)"/', $loginPage, $m);
httpPost($BASE . '/index.php?r=auth/login', ['_token' => $m[1] ?? '', 'email' => $uEmail, 'password' => $pwd], $uJar);

$userList = httpGet($BASE . '/index.php?r=order/list', $uJar);
$chk('订单列表渲染有效期（剩余 N 天）', strpos($userList, '剩余 ') !== false && strpos($userList, '天（') !== false);
$chk('已到期订单红标展示', strpos($userList, '已到期') !== false);

/* ==================== 第七节：弹窗公告（1.7.0 改版） ==================== */
echo "\n[7] 弹窗公告\n";
$ANN = "新年大促进行中！\n全场主机 8 折优惠码：LY2026";
$adminPost('admin/setting/save', ['group' => 'site', 'site_announce' => $ANN]);
$html = httpGet($BASE . '/index.php?r=home/index');
$chk('首页渲染弹窗（announceModal）', strpos($html, 'announceModal') !== false);
$chk('横条已移除（无 announce-bar）', strpos($html, 'announce-bar') === false);
$chk('多行内容 nl2br 输出', strpos($html, '新年大促进行中！<br />') !== false);
$chk('关闭按钮与记忆脚本渲染', strpos($html, 'LYAnn.close') !== false && strpos($html, 'ly_announce_closed') !== false);
$chk('hash 按原始内容计算', strpos($html, "LYAnn.close('" . md5($ANN) . "')") !== false);

$xss = '<script>alert(1)</script>维护公告';
$adminPost('admin/setting/save', ['group' => 'site', 'site_announce' => $xss]);
$html = httpGet($BASE . '/index.php?r=home/index');
$chk('XSS：无裸 <script>alert', stripos($html, '<script>alert') === false);
$chk('XSS：恶意内容转义形态出现', strpos($html, '&lt;script&gt;') !== false);
$chk('XSS：hash 按原始恶意内容计算', strpos($html, "LYAnn.close('" . md5($xss) . "')") !== false);

$adminPost('admin/setting/save', ['group' => 'site', 'site_announce' => '']);
$html = httpGet($BASE . '/index.php?r=home/index');
$chk('空公告不渲染弹窗', strpos($html, 'announceModal') === false);

/* ==================== 清理 ==================== */
echo "\n[8] 清理\n";
$adminPost('admin/setting/save', ['group' => 'site', 'site_announce' => '新站上线，全场特惠，支付后自动发货！']);
$chk('恢复默认公告', (string)$dbVal("SELECT v FROM ly_settings WHERE k='site_announce'") === '新站上线，全场特惠，支付后自动发货！');
$clearSmtp();
foreach ([$oidA, $oidB, $oidC, $oidD, $oidE] as $oid) {
    if ($oid > 0) { $db->delete('ly_orders', 'id=?', [$oid]); }
}
$db->delete('ly_stocks', 'product_id=?', [$pid]);
$db->delete('ly_products', 'id=?', [$pid]);
$db->delete('ly_users', 'id=?', [$uid]);
$db->delete('ly_admins', 'id=?', [$adminId]);
$chk('清理测试数据', (int)$dbVal('SELECT COUNT(*) FROM ly_products WHERE id=?', [$pid]) === 0);
if (is_resource($smtpProc)) {
    proc_terminate($smtpProc); proc_close($smtpProc);
}
@unlink($smtpServerFile); @unlink($smtpReady);

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "有效期体系测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
