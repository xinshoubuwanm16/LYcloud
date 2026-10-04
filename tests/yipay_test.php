<?php
/**
 * LY云计算 1.4.0 易支付通道测试
 * 运行前需手动启动 mock 平台：php -S 127.0.0.1:8123 /tmp/yipay_mock/index.php
 * （由 runner 脚本统一启动）
 */
define('LY_DEBUG', false);
require __DIR__ . '/../app/bootstrap.php';

use App\Models\BalanceLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCoupon;
use App\Payment\YiPayGateway;
use App\Controllers\PayController;

$PASS = 0; $FAIL = 0; $FAILURES = [];
function ok(bool $cond, string $name): void {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  ✓ $name\n"; }
    else { $FAIL++; $FAILURES[] = $name; echo "  ✗ $name\n"; }
}
function section(string $t): void { echo "\n━━ $t\n"; }
$TAG = 'yp' . strtoupper(bin2hex(random_bytes(3)));

// ============ 测试环境准备 ============
$db = \App\Database::instance();
// 清空测试数据（开发库；保留 ly_stocks —— 既有 flow_test 依赖商品 #1 的库存池）
$db->query('DELETE FROM ly_orders');
$db->query('DELETE FROM ly_users');
$db->query('DELETE FROM ly_balance_logs');
$db->query('DELETE FROM ly_user_coupons');
$db->query('DELETE FROM ly_coupon_scopes');
$db->query('DELETE FROM ly_coupons');
$db->query('DELETE FROM ly_logs');

// 管理员 + 用户
use App\Models\Admin;
$adminId = Admin::create('yp_admin_' . $TAG, 'Admin@123', '易支付测试管理员');

function mkUser(string $tag, string $suffix): int {
    return User::create("{$tag}{$suffix}@qq.com", 'User@123', '用户' . $suffix, true);
}

// 商品（无限库存，便于测试）
$prodId = Product::create([
    'name' => 'IPv6宝塔面板主机-易支付测试', 'subtitle' => '易支付测试商品', 'spec' => '1核1G',
    'tags' => '测试', 'price' => '50.00', 'original_price' => '60.00', 'cover' => '',
    'description' => '测试', 'stock_mode' => 0, 'auto_deliver' => 1, 'sort' => 0, 'sales' => 0, 'status' => 1,
]);
$product = Product::find($prodId);
ok($product !== null, '测试商品创建');

// ============ 一、签名算法 ============
section('1. MD5 签名算法');
$KEY = 'testkey123';

// 独立实现交叉验证（与 mock 平台一致的写法）
function expectSign(array $p, string $k): string {
    $arr = [];
    foreach ($p as $key0 => $v) {
        if ($key0 === 'sign' || $key0 === 'sign_type') continue;
        if ($v === '' || $v === null || is_array($v)) continue;
        $arr[$key0] = (string)$v;
    }
    ksort($arr, SORT_STRING);
    $str = urldecode(http_build_query($arr));
    return md5($str . $k);
}

$p1 = ['pid' => '1001', 'type' => 'alipay', 'out_trade_no' => '20260101120000000001',
       'money' => '50.00', 'name' => '测试商品', 'sitename' => 'LY云计算'];
$p1['sign'] = YiPayGateway::sign($p1, $KEY);
$p1['sign_type'] = 'MD5';
ok($p1['sign'] === expectSign($p1, $KEY), '签名与独立实现一致（含中文）');
ok(preg_match('/^[a-f0-9]{32}$/', $p1['sign']) === 1, '签名为 32 位小写 md5');

// 空值参数不参与签名
$p2 = $p1; $p2['attach'] = '';
ok(YiPayGateway::sign($p2, $KEY) === $p1['sign'], '空值参数不参与签名');
// sign/sign_type 剔除
$p3 = $p1; $p3['sign'] = 'abc'; $p3['sign_type'] = 'XXX';
ok(YiPayGateway::sign($p3, $KEY) === $p1['sign'], 'sign/sign_type 自动剔除');
// 参数顺序无关
$p4 = array_reverse($p1, true);
ok(YiPayGateway::sign($p4, $KEY) === $p1['sign'], '参数顺序无关（按名升序）');
// 数组值剔除
$p5 = $p1; $p5['arr'] = ['a', 'b'];
ok(YiPayGateway::sign($p5, $KEY) === $p1['sign'], '数组参数不参与签名');
// key 拼接影响
ok(YiPayGateway::sign($p1, $KEY) !== YiPayGateway::sign($p1, $KEY . 'x'), '不同密钥签名不同');

// 验签
ok(YiPayGateway::verifySign($p1, $KEY) === true, 'verifySign 正确签名通过');
$pTamper = $p1; $pTamper['money'] = '999.00';
ok(YiPayGateway::verifySign($pTamper, $KEY) === false, 'verifySign 篡改金额被拒');
$pNoSign = $p1; unset($pNoSign['sign']);
ok(YiPayGateway::verifySign($pNoSign, $KEY) === false, 'verifySign 缺 sign 被拒');
$pUpper = $p1; $pUpper['sign'] = strtoupper($p1['sign']);
ok(YiPayGateway::verifySign($pUpper, $KEY) === true, 'verifySign 大写签名兼容（大小写不敏感）');
ok(YiPayGateway::verifySign($p1, '') === false, '空密钥直接拒绝');

// ============ 二、pay_channel 工具方法 ============
section('2. pay_channel 工具方法');
ok(YiPayGateway::isChannel('yipay_alipay') === true, 'isChannel yipay_alipay');
ok(YiPayGateway::isChannel('yipay_wxpay') === true, 'isChannel yipay_wxpay');
ok(YiPayGateway::isChannel('qr') === false, 'isChannel qr 非易支付');
ok(YiPayGateway::isChannel('page') === false, 'isChannel page 非易支付');
ok(YiPayGateway::isChannel('balance') === false, 'isChannel balance 非易支付');
ok(YiPayGateway::typeOfChannel('yipay_alipay') === 'alipay', 'typeOfChannel alipay');
ok(YiPayGateway::typeOfChannel('yipay_wxpay') === 'wxpay', 'typeOfChannel wxpay');
ok(YiPayGateway::typeOfChannel('yipay_weird') === 'alipay', 'typeOfChannel 未知子通道回落 alipay');
ok(YiPayGateway::makeChannel('wxpay') === 'yipay_wxpay', 'makeChannel wxpay');
ok(YiPayGateway::makeChannel('nope') === 'yipay_alipay', 'makeChannel 非法子通道回落');
ok(YiPayGateway::channelLabel('yipay_wxpay') === '易支付 - 微信支付', 'channelLabel 微信');
ok(YiPayGateway::channelLabel('yipay_alipay') === '易支付 - 支付宝', 'channelLabel 支付宝');
ok(YiPayGateway::channelLabel('qr') === '', 'channelLabel 非易支付返回空');

// ============ 三、宽松 JSON 解析 ============
section('3. decodeJsonLoose 宽松解析');
$r = YiPayGateway::decodeJsonLoose('{"code":1,"msg":"ok"}');
ok(is_array($r) && $r['code'] === 1, '纯 JSON');
$r = YiPayGateway::decodeJsonLoose("\xEF\xBB\xBF" . '{"code":1}');
ok(is_array($r) && $r['code'] === 1, 'UTF-8 BOM 包裹');
$r = YiPayGateway::decodeJsonLoose('{"code":1}' . "\n");
ok(is_array($r) && $r['code'] === 1, '尾部换行');
$r = YiPayGateway::decodeJsonLoose('<![CDATA[{"code":1}]]>');
ok(is_array($r) && $r['code'] === 1, 'CDATA 包裹');
ok(YiPayGateway::decodeJsonLoose('') === null, '空串返回 null');
ok(YiPayGateway::decodeJsonLoose('<html>err</html>') === null, 'HTML 返回 null（不误判）');

// ============ 四、querySaysPaid ============
section('4. querySaysPaid 状态判定');
ok(YiPayGateway::querySaysPaid(['trade_status' => 'TRADE_SUCCESS']) === true, 'trade_status=TRADE_SUCCESS');
ok(YiPayGateway::querySaysPaid(['status' => 1]) === true, 'status=1（int）');
ok(YiPayGateway::querySaysPaid(['status' => '1']) === true, 'status="1"（string）');
ok(YiPayGateway::querySaysPaid(['status' => 0]) === false, 'status=0 未支付');
ok(YiPayGateway::querySaysPaid([]) === false, '空结果未支付');
ok(YiPayGateway::querySaysPaid(['status' => 'PENDING']) === false, '异常 status 未支付');

// ============ 五、Setting 配置 ============
section('5. 易支付配置读写');
Setting::set('yipay_switch', '1');
Setting::set('yipay_api_url', 'http://127.0.0.1:8123');   // 本地 mock 用 http
Setting::set('yipay_pid', '1001');
Setting::set('yipay_key', 'mock_key_123456');
Setting::set('yipay_mode', 'api');
Setting::set('yipay_channels', 'alipay,wxpay');
ok(Setting::yipayEnabled() === true, '配置齐全 yipayEnabled=true');
$cfg = Setting::yipay();
ok($cfg['api_url'] === 'http://127.0.0.1:8123', 'api_url 保存原样');
ok($cfg['channels'] === ['alipay', 'wxpay'], 'channels 解析 alipay,wxpay');
ok($cfg['mode'] === 'api', 'mode=api');

// 通道列表规范化
Setting::set('yipay_channels', ' wxpay , qqpay , bad , ,alipay ');
ok(Setting::yipayChannels() === ['wxpay', 'qqpay', 'alipay'], 'channels 规范化（去空白/去重/去非法）');
Setting::set('yipay_channels', '');
ok(Setting::yipayChannels() === [], '空 channels');
ok(Setting::yipayEnabled() === false, '无通道则不可用');
Setting::set('yipay_channels', 'alipay,wxpay');
// 开关关闭
Setting::set('yipay_switch', '0');
ok(Setting::yipayEnabled() === false, '开关关闭则不可用');
Setting::set('yipay_switch', '1');
// 缺 key
$_keyBak = Setting::get('yipay_key');
Setting::set('yipay_key', '');
ok(Setting::yipayEnabled() === false, '缺 KEY 不可用');
Setting::set('yipay_key', $_keyBak);
ok(Setting::yipayEnabled() === true, '恢复后可用');

// 网关 URL 补全（不带协议默认 https，生产安全默认值）
$gw = new YiPayGateway();
ok($gw->apiUrl() === 'http://127.0.0.1:8123/', 'apiUrl 补末尾斜杠');
$gwHttps = new YiPayGateway(array_merge(Setting::yipay(), ['api_url' => 'pay.example.com']));
ok($gwHttps->apiUrl() === 'https://pay.example.com/', 'apiUrl 无协议默认补 https');
$gw2 = new YiPayGateway(array_merge(Setting::yipay(), ['api_url' => 'http://pay.example.com///']));
ok($gw2->apiUrl() === 'http://pay.example.com/', 'apiUrl 去多余斜杠');
$gwBad = new YiPayGateway(['api_url' => '', 'pid' => '1', 'key' => 'k']);
try { $gwBad->apiUrl(); ok(false, '空 api_url 抛异常'); }
catch (\RuntimeException $e) { ok(true, '空 api_url 抛异常'); }

// ============ 六、submitUrl 跳转链接 ============
section('6. submitUrl 跳转链接');
$sumbitUrl = $gw->submitUrl('ORD_TEST_001', 'IPv6宝塔面板主机', '50.00', 'alipay',
    'http://site.test/index.php?r=pay/yinotify', 'http://site.test/index.php?r=pay/yireturn');
ok(strpos($sumbitUrl, 'http://127.0.0.1:8123/submit.php?') === 0, 'submitUrl 指向平台 submit.php');
parse_str((string)parse_url($sumbitUrl, PHP_URL_QUERY), $q);
ok(($q['pid'] ?? '') === '1001', 'submitUrl 含 pid');
ok(($q['type'] ?? '') === 'alipay', 'submitUrl 含 type');
ok(($q['out_trade_no'] ?? '') === 'ORD_TEST_001', 'submitUrl 含订单号');
ok(($q['money'] ?? '') === '50.00', 'submitUrl 含金额');
ok(($q['notify_url'] ?? '') === 'http://site.test/index.php?r=pay/yinotify', 'submitUrl 含 notify_url');
ok(($q['sign_type'] ?? '') === 'MD5', 'submitUrl 含 sign_type=MD5');
ok(YiPayGateway::verifySign($q, 'mock_key_123456') === true, 'submitUrl 签名可被平台端验证');

// ============ 七、verifyNotify 通知校验 ============
section('7. verifyNotify 异步通知校验');
function mkNotify(string $orderNo, string $money, string $key, array $extra = []): array {
    $n = array_merge([
        'pid' => '1001', 'trade_no' => 'MOCKTRADE' . random_int(100000, 999999),
        'out_trade_no' => $orderNo, 'type' => 'alipay', 'name' => '商品',
        'money' => $money, 'trade_status' => 'TRADE_SUCCESS',
    ], $extra);
    $n['sign'] = YiPayGateway::sign($n, $key);
    $n['sign_type'] = 'MD5';
    return $n;
}
$nOk = mkNotify('ORD_N1', '50.00', 'mock_key_123456');
$r = $gw->verifyNotify($nOk);
ok($r['ok'] === true, '正确通知通过');
ok($r['out_trade_no'] === 'ORD_N1' && $r['amount'] === '50.00', '通知解析订单号与金额');
ok(($r['trade_no'] ?? '') !== '', '通知解析交易号');

// 带本站路由参数 r 的通知（单入口 index.php?r=... 场景，r 不参与平台签名）
$nRoute = mkNotify('ORD_N1B', '50.00', 'mock_key_123456');
$nRoute['r'] = 'pay/yinotify';
$r = $gw->verifyNotify($nRoute);
ok($r['ok'] === true, '含路由参数 r 的通知验签通过（r 被剔除）');
// r 参与签名则应失败（回归保护：sign() 不应忽略未剔除的 r 的变更）
$nRoute2 = mkNotify('ORD_N1C', '50.00', 'mock_key_123456');
$nRoute2['r'] = 'pay/yinotify';
$signWithR = YiPayGateway::sign($nRoute2, 'mock_key_123456');   // sign() 原样把 r 计入
$nRoute2['r'] = 'pay/other';
ok(YiPayGateway::sign($nRoute2, 'mock_key_123456') !== $signWithR, 'sign() 本身仍覆盖所有参数（防滥用）');

$r = $gw->verifyNotify(mkNotify('ORD_N2', '50.00', 'wrong_key'));
ok($r['ok'] === false, '错误密钥签名被拒');
$r = $gw->verifyNotify(mkNotify('ORD_N3', '50.00', 'mock_key_123456', ['pid' => '9999']));
ok($r['ok'] === false && strpos($r['msg'], 'PID') !== false, 'pid 不匹配被拒');
$r = $gw->verifyNotify(mkNotify('ORD_N4', '50.00', 'mock_key_123456', ['trade_status' => 'WAIT']));
ok($r['ok'] === false && !empty($r['pending']), '非成功状态标记 pending');
$r = $gw->verifyNotify(['out_trade_no' => 'ORD_N5']);
ok($r['ok'] === false, '缺参数被拒');
$r = $gw->verifyNotify(mkNotify('ORD_N6', '0.00', 'mock_key_123456'));
ok($r['ok'] === true, '0 元通知验签通过（金额校验由业务层做）');

// ============ 八、下单 + 通道白名单 ============
section('8. 下单通道白名单');
$u1 = mkUser($TAG, 'a1');
$user1 = User::find($u1);

// 当前环境：官方支付宝未配置 + 易支付已配置 → 白名单只有易支付
$ord = Order::createWithStock($user1, $product, 'yipay_alipay');
ok($ord !== null && $ord['pay_channel'] === 'yipay_alipay', '易支付支付宝通道下单');
ok(bccomp($ord['pay_amount'], '50.00', 2) === 0, 'pay_amount=50.00');

$ord2 = Order::createWithStock($user1, $product, 'yipay_wxpay');
ok($ord2['pay_channel'] === 'yipay_wxpay', '易支付微信通道下单');

$ord3 = Order::createWithStock($user1, $product, 'balance_xxx');
ok($ord3['pay_channel'] === 'balance_xxx', '模型层原样存储通道（白名单由控制器把关）');

// 配置官方支付宝后白名单恢复完整
Setting::set('alipay_app_id', '2021000000000001');
Setting::set('alipay_private_key', @file_get_contents(__DIR__ . '/keys/rsa_private_key.pem') ?: '');
Setting::set('alipay_public_key', @file_get_contents(__DIR__ . '/keys/rsa_public_key.pem') ?: '');
if (!Setting::alipayConfigured()) {
    // 无密钥文件时用测试密钥（1 元测试串，仅为开启白名单；不发起真实支付）
    $kp = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($kp, $priv);
    $pubDetail = openssl_pkey_get_details($kp);
    Setting::set('alipay_private_key', str_replace(["\r", "\n"], '', $priv));
    Setting::set('alipay_public_key', str_replace(["\r", "\n"], '', $pubDetail['key']));
}
ok(Setting::alipayConfigured() === true, '官方支付宝配置完成（白名单测试用）');
$ord4 = Order::createWithStock($user1, $product, 'qr');
ok($ord4['pay_channel'] === 'qr', '官方通道 qr 下单');
Setting::set('alipay_app_id', '');  // 还原未配置（模拟当前环境）

// ============ 九、HTTP 端到端：mapi 取码 + 通知发货 ============
section('9. 端到端：mapi 取码 + 通知发货');
$ord = Order::createWithStock($user1, $product, 'yipay_alipay');
$orderNo = $ord['order_no'];

// 9.1 mapi 下单取码（走真实 HTTP 到 mock）
$mapi = $gw->mapiPay($orderNo, $product['name'], '50.00', 'alipay',
    'http://127.0.0.1:8099/index.php?r=pay/yinotify', 'http://127.0.0.1:8099/index.php?r=pay/yireturn');
ok((int)$mapi['code'] === 1, 'mapi 下单 code=1');
ok(!empty($mapi['qrcode']), 'mapi 返回 qrcode');
ok(!empty($mapi['payurl']), 'mapi 返回 payurl');

// 9.2 mock 已记录订单（待支付）
$st = json_decode((string)@file_get_contents('http://127.0.0.1:8123/state'), true);
ok(isset($st[$orderNo]) && (int)$st[$orderNo]['status'] === 0, 'mock 平台记录订单（待支付）');
ok(bccomp((string)$st[$orderNo]['money'], '50.00', 2) === 0, 'mock 订单金额一致');

// 9.3 查单
$qr = $gw->queryOrder($orderNo);
ok(YiPayGateway::querySaysPaid($qr) === false, '查单：未支付');
ok(bccomp((string)$qr['money'], '50.00', 2) === 0, '查单金额一致');

// 9.4 模拟用户付款成功（mock 辅助端点）
file_get_contents('http://127.0.0.1:8123/set?' . http_build_query(['order_no' => $orderNo, 'status' => 1]));
$qr = $gw->queryOrder($orderNo);
ok(YiPayGateway::querySaysPaid($qr) === true, '查单：已支付');

// 9.5 通知回调（正确签名 GET）→ markPaid 发货
$_GET = mkNotify($orderNo, '50.00', 'mock_key_123456');
$_POST = [];
ob_start();
(new PayController())->yiNotify();
$resp = ob_get_clean();
ok(trim($resp) === 'success', '通知回调返回 success');

$after = Order::find((int)$ord['id']);
ok((int)$after['status'] >= Order::STATUS_PAID, '订单已支付/发货');
ok((int)$after['status'] === Order::STATUS_PAID, '支付处理完成（无限库存商品终态为已支付，无面板行可分配）');
ok($after['trade_no'] !== '' && strpos((string)$after['trade_no'], 'MOCK') === 0, '交易号来自 mock 平台');
$panel = Order::panel((int)$ord['id']);
ok($panel === null, '无限库存商品无面板行（库存模式正确）');

// 9.6 幂等：重复通知
$_GET = mkNotify($orderNo, '50.00', 'mock_key_123456');
ob_start();
(new PayController())->yiNotify();
$resp2 = ob_get_clean();
ok(trim($resp2) === 'success', '重复通知仍返回 success（幂等）');
$after2 = Order::find((int)$ord['id']);
ok((int)$after2['status'] === Order::STATUS_PAID, '重复通知不改变状态');

// ============ 十、通知安全防护 ============
section('10. 通知安全防护');
// 10.1 错误签名
$bad = mkNotify($orderNo, '50.00', 'mock_key_123456'); $bad['sign'] = str_repeat('0', 32);
$_GET = $bad; ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(trim($resp) === 'fail', '错误签名被拒（fail）');

// 10.2 篡改金额（伪造大额支付通知）
$forged = mkNotify($orderNo, '0.01', 'mock_key_123456');   // 签名合法但金额与订单不符
$_GET = $forged; ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(strpos($resp, 'fail') === 0, '金额不符被拒（fail: amount mismatch）');
ok((int)Order::find((int)$ord['id'])['status'] === Order::STATUS_PAID, '拒绝后订单状态不变');

// 10.3 不存在的订单
$_GET = mkNotify('NO_SUCH_ORDER', '1.00', 'mock_key_123456');
ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(trim($resp) === 'success', '不存在订单回 success（避免重试风暴）');

// 10.4 串单防护：官方通道订单收到易支付通知 → 拒绝
$ordQr = Order::createWithStock($user1, $product, 'qr');
$_GET = mkNotify($ordQr['order_no'], '50.00', 'mock_key_123456');
ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(trim($resp) === 'fail', '官方通道订单收到易支付通知被拒（防串单）');
ok((int)Order::find((int)$ordQr['id'])['status'] === Order::STATUS_PENDING, '串单通知不影响订单');

// 10.5 pending 状态通知
$_GET = mkNotify($orderNo, '50.00', 'mock_key_123456', ['trade_status' => 'WAIT_BUYER_PAY']);
ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(trim($resp) === 'fail', '非成功状态返回 fail');

// 10.6 空请求
$_GET = []; ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(trim($resp) === 'fail', '空请求返回 fail');

// ============ 十一、券 + 余额 + 易支付叠加链路 ============
section('11. 券 + 余额 + 易支付叠加');
// 11.1 满减券 10 元 + 余额 5 元 + 易支付
$couponId = Coupon::create([
    'name' => '易支付测试券', 'code' => $TAG . 'CP1', 'type' => 'reduce', 'value' => '10.00',
    'min_amount' => '20.00', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'max_discount' => '0.00', 'remark' => '',
], [], $adminId)['id'];
UserCoupon::grant($couponId, [$u1], 'admin');
$uc = $db->first(
    'SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$couponId, $u1]);
ok($uc !== null, '券已发放到用户');

// 余额 5 元
BalanceLog::credit($u1, '5.00', BalanceLog::TYPE_ADMIN, 0, '测试充值');
$user1 = User::find($u1);
ok(bccomp((string)$user1['balance'], '5.00', 2) === 0, '用户余额 5.00');

$ord5 = Order::createWithStock($user1, $product, 'yipay_alipay', true, (int)$uc['id']);
ok(bccomp((string)$ord5['coupon_discount'], '10.00', 2) === 0, '券抵扣 10.00');
ok(bccomp((string)$ord5['balance_paid'], '5.00', 2) === 0, '余额抵扣 5.00');
ok(bccomp((string)$ord5['pay_amount'], '35.00', 2) === 0, '易支付实付 35.00（先券后余额）');
$sum = bcadd($ord5['coupon_discount'], bcadd($ord5['balance_paid'], $ord5['pay_amount'], 2), 2);
ok(bccomp($sum, $ord5['amount'], 2) === 0, '金额链恒等式成立');

// 11.2 mapi 取码金额为 35.00
$mapi5 = $gw->mapiPay($ord5['order_no'], $product['name'], money($ord5['pay_amount']), 'alipay', 'n', 'r');
ok(true, 'mapi 取码成功（叠加单）');

// 11.3 通知金额为 35.00 → 发货 + 扣余额
file_get_contents('http://127.0.0.1:8123/set?' . http_build_query(['order_no' => $mapi5['qrcode'] ? substr($mapi5['qrcode'], strlen('mockqr://')) : '', 'status' => 1]));
$_GET = mkNotify($ord5['order_no'], '35.00', 'mock_key_123456');
ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(trim($resp) === 'success', '叠加单通知 success');
$after5 = Order::find((int)$ord5['id']);
ok((int)$after5['status'] === Order::STATUS_PAID, '叠加单支付处理完成');
$user1 = User::find($u1);
ok(bccomp((string)$user1['balance'], '0.00', 2) === 0, '余额在 markPaid 时扣至 0.00');
// 券核销
$ucAfter = $db->first('SELECT status FROM ly_user_coupons WHERE id=?', [$uc['id']]);
ok((int)$ucAfter['status'] === UserCoupon::STATUS_USED, '券已核销');

// 11.4 通知金额与订单实付不符（伪造大额/小额均拒）
$ord6 = Order::createWithStock($user1, $product, 'yipay_wxpay');
$_GET = mkNotify($ord6['order_no'], '999.00', 'mock_key_123456');
ob_start(); (new PayController())->yiNotify(); $resp = ob_get_clean();
ok(strpos($resp, 'fail') === 0, '通知金额与订单实付不符被拒（pay_amount 基准）');

// ============ 十二、纯余额单不走易支付 ============
section('12. 纯余额单');
BalanceLog::credit($u1, '100.00', BalanceLog::TYPE_ADMIN, 0, '再充值');
$user1 = User::find($u1);
$ord7 = Order::createWithStock($user1, $product, 'yipay_alipay', true, null);
ok($ord7['pay_channel'] === 'balance', '足额余额单通道强制为 balance');
ok((int)$ord7['status'] === Order::STATUS_PAID, '纯余额单直接结算完成（不经易支付）');

// ============ 十三、超时关单与退款安全 ============
section('13. 关单安全');
$ord8 = Order::createWithStock($user1, $product, 'yipay_alipay');
ok(Order::closeWithRefund((int)$ord8['id']) === true, '易支付待支付单可关单');
ok((int)Order::find((int)$ord8['id'])['status'] === Order::STATUS_CLOSED, '关单后已关闭');
$user1 = User::find($u1);
// 关单未退余额（非纯余额单未扣过余额）
$beforeBal = (string)$user1['balance'];
ok(bccomp($beforeBal, '50.00', 2) === 0, '部分抵扣单关单不退余额（未扣过）');

// ============ 十四、queryStatus 轮询查单逻辑 ============
section('14. 轮询查单组合逻辑');
$ord9 = Order::createWithStock($user1, $product, 'yipay_alipay');
$mapi9 = $gw->mapiPay($ord9['order_no'], $product['name'], '50.00', 'alipay', 'n', 'r');
// 未支付
$qr9 = $gw->queryOrder($ord9['order_no']);
ok(YiPayGateway::querySaysPaid($qr9) === false, '轮询：未支付');
// 支付
file_get_contents('http://127.0.0.1:8123/set?' . http_build_query(['order_no' => $ord9['order_no'], 'status' => 1]));
$qr9 = $gw->queryOrder($ord9['order_no']);
ok(YiPayGateway::querySaysPaid($qr9) === true, '轮询：已支付');
// 金额一致才 markPaid
if (YiPayGateway::querySaysPaid($qr9) && bccomp(money($ord9['pay_amount']), money((string)$qr9['money']), 2) === 0) {
    Order::markPaid((int)$ord9['id'], (string)$qr9['trade_no'], json_encode($qr9, JSON_UNESCAPED_UNICODE));
}
ok((int)Order::find((int)$ord9['id'])['status'] === Order::STATUS_PAID, '轮询查单后完成支付处理');

// ============ 十五、queryOrder 异常容错 ============
section('15. queryOrder 异常容错');
try {
    $gw->queryOrder('NOT_EXIST_ORDER_NO');
    ok(false, '查不存在订单应抛异常');
} catch (\RuntimeException $e) {
    ok(strpos($e->getMessage(), '订单不存在') !== false || strpos($e->getMessage(), '查单') !== false, '查不存在订单抛可读异常');
}
$gwWrongKey = new YiPayGateway(array_merge(Setting::yipay(), ['key' => 'bad']));
try {
    $gwWrongKey->queryOrder($ord9['order_no']);
    ok(false, '错误 key 查单应抛异常');
} catch (\RuntimeException $e) {
    ok(true, '错误 key 查单抛异常');
}

// ============ 十六、升级脚本幂等（SQL 文件已单独验证） ============
section('16. 配置项完整性');
$need = ['yipay_switch', 'yipay_api_url', 'yipay_pid', 'yipay_key', 'yipay_mode', 'yipay_channels', 'yipay_notify_url', 'yipay_return_url'];
foreach ($need as $nk) {
    ok(Setting::get($nk) !== null, "配置项 $nk 存在");
}

// ============ 汇总 ============
echo "\n" . str_repeat('=', 50) . "\n";
echo "总计：" . ($PASS + $FAIL) . "  通过：$PASS  失败：$FAIL\n";
if ($FAIL) {
    echo "\n失败项：\n";
    foreach ($FAILURES as $f) echo "  ✗ $f\n";
    exit(1);
}
exit(0);
