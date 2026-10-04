<?php
/**
 * 支付宝 RSA2 签名 / 验签 / 回调校验 测试
 */
define('LY_ROOT', '/workspace/lycloud');
define('LY_DEBUG', true);
define('LY_VERSION', '1.0.0');

require LY_ROOT . '/config/config.php';
require LY_ROOT . '/app/helpers.php';

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = LY_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
date_default_timezone_set('Asia/Shanghai');

use App\Payment\AlipaySign;
use App\Payment\AlipayGateway;

$pass = 0; $fail = 0;
function check($label, $ok, $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✔ $label\n"; }
    else { $fail++; echo "  ✘ $label" . ($extra ? "  [$extra]" : "") . "\n"; }
}

echo "\n===== 支付宝接口测试 =====\n\n";

// ---------- 1. 密钥生成 ----------
echo "[1] RSA2 密钥对生成\n";
$kp = AlipaySign::generateKeyPair(2048);
check('私钥已生成', strpos($kp['private'], 'PRIVATE KEY') !== false);
check('公钥已生成', strpos($kp['public'], 'PUBLIC KEY') !== false);
check('公钥同时提供 PKCS1 格式', strpos($kp['public_pkcs1'], 'RSA PUBLIC KEY') !== false);

// ---------- 2. 签名与验签 ----------
echo "\n[2] 签名 / 验签（RSA2 = SHA256withRSA）\n";
$params = [
    'app_id'      => '2021000000000000',
    'method'      => 'alipay.trade.precreate',
    'charset'     => 'utf-8',
    'sign_type'   => 'RSA2',
    'timestamp'   => '2026-10-01 14:30:00',
    'version'     => '1.0',
    'out_trade_no'=> '20261001143000123456',
    'total_amount'=> '9.90',
    'subject'     => 'IPv6宝塔面板主机 · 入门版',
];
$sign = AlipaySign::sign($params, $kp['private']);
check('签名生成成功', $sign !== '' && base64_decode($sign, true) !== false);
check('签名长度为 344 字符（2048 位 RSA）', strlen($sign) === 344, 'len=' . strlen($sign));

$params['sign'] = $sign;
check('正确签名验签通过', AlipaySign::verify($params, $kp['public']) === true);

// 篡改金额后应验签失败
$tampered = $params;
$tampered['total_amount'] = '0.01';
check('篡改金额后验签失败', AlipaySign::verify($tampered, $kp['public']) === false);

// 篡改订单号后应验签失败
$tampered2 = $params;
$tampered2['out_trade_no'] = '20261001143000999999';
check('篡改订单号后验签失败', AlipaySign::verify($tampered2, $kp['public']) === false);

// 用错误公钥验签应失败
$kp2 = AlipaySign::generateKeyPair(2048);
check('错误公钥验签失败', AlipaySign::verify($params, $kp2['public']) === false);

// ---------- 3. 签名原串规则 ----------
echo "\n[3] 待签名串规则\n";
$src = AlipaySign::buildSignSource(['b' => '2', 'a' => '1', 'sign' => 'xxx', 'sign_type' => 'RSA2', 'empty' => '']);
check('参数按字典序升序排列', $src === 'a=1&b=2', "实际: $src");
check('排除 sign 与空值参数', strpos($src, 'sign=') === false && strpos($src, 'empty') === false);

// ---------- 4. 中文与特殊字符 ----------
echo "\n[4] 中文与特殊字符签名\n";
$cnParams = [
    'subject'    => 'IPv6宝塔面板主机 · 入门版（含中文）',
    'body'       => '测试 & 特殊字符 <>"\'',
    'out_trade_no' => 'ORDER_2026-10-01',
];
$cnSign = AlipaySign::sign($cnParams, $kp['private']);
$cnParams['sign'] = $cnSign;
$cnParams['sign_type'] = 'RSA2';
check('中文参数签名并验签通过', AlipaySign::verify($cnParams, $kp['public']) === true);

// ---------- 5. 密钥格式兼容 ----------
echo "\n[5] 密钥格式兼容性\n";
check('PKCS8 私钥可解析', openssl_pkey_get_private(AlipaySign::formatPrivateKey($kp['private'])) !== false);
$stripped = AlipaySign::stripKey($kp['private']);
check('去除 PEM 头尾的裸密钥可自动还原', openssl_pkey_get_private(AlipaySign::formatPrivateKey($stripped)) !== false);
$strippedPub = AlipaySign::stripKey($kp['public']);
check('裸公钥可自动还原', openssl_pkey_get_public(AlipaySign::formatPublicKey($strippedPub)) !== false);

// ---------- 6. 网关地址 ----------
echo "\n[6] 网关环境切换\n";
$gwSandbox = new AlipayGateway(['app_id'=>'x','private_key'=>$kp['private'],'public_key'=>$kp['public'],'gateway'=>'sandbox']);
check('沙箱网关地址正确', strpos($gwSandbox->gatewayUrl(), 'sandbox') !== false, $gwSandbox->gatewayUrl());
$gwProd = new AlipayGateway(['app_id'=>'x','private_key'=>$kp['private'],'public_key'=>$kp['public'],'gateway'=>'prod']);
check('正式网关地址正确', $gwProd->gatewayUrl() === 'https://openapi.alipay.com/gateway.do', $gwProd->gatewayUrl());

// ---------- 7. 电脑网站支付 URL 生成 ----------
echo "\n[7] 电脑网站支付跳转地址\n";
$url = $gwProd->pagePayUrl('20261001143000123456', 'IPv6宝塔面板主机', '9.90',
    'https://shop.example.com/index.php?r=pay/notify',
    'https://shop.example.com/index.php?r=pay/return');
check('跳转 URL 指向支付宝网关', strpos($url, 'https://openapi.alipay.com/gateway.do?') === 0, substr($url, 0, 60));
check('包含 method 参数', strpos($url, 'alipay.trade.page.pay') !== false);
check('包含 sign 参数', strpos($url, 'sign=') !== false);
check('包含 product_code=FAST_INSTANT_TRADE_PAY', strpos($url, 'FAST_INSTANT_TRADE_PAY') !== false);
check('包含 notify_url', strpos($url, 'notify_url=') !== false);
check('包含 return_url', strpos($url, 'return_url=') !== false);

// 解出 URL 中的参数并用公钥验签，模拟支付宝侧校验
parse_str(parse_url($url, PHP_URL_QUERY), $parsed);
check('生成的跳转链接签名可被验证', AlipaySign::verify($parsed, $kp['public']) === true);

// ---------- 8. 异步通知校验 ----------
echo "\n[8] 异步通知（notify）校验\n";
$gw = new AlipayGateway([
    'app_id' => '2021000000000000',
    'private_key' => $kp['private'],
    'public_key'  => $kp['public'],
    'gateway' => 'sandbox',
]);

$notify = [
    'app_id'       => '2021000000000000',
    'out_trade_no' => '20261001143000123456',
    'trade_no'     => '2026100122001234567890',
    'trade_status' => 'TRADE_SUCCESS',
    'total_amount' => '9.90',
    'seller_id'    => '2088000000000000',
    'gmt_payment'  => '2026-10-01 14:31:00',
];
$notify['sign'] = AlipaySign::sign($notify, $kp['private']);
$notify['sign_type'] = 'RSA2';

$r = $gw->verifyNotify($notify);
check('合法通知校验通过', $r['ok'] === true, $r['msg'] ?? '');
check('正确解析商户订单号', ($r['out_trade_no'] ?? '') === '20261001143000123456');
check('正确解析支付宝交易号', ($r['trade_no'] ?? '') === '2026100122001234567890');
check('正确解析支付金额', ($r['amount'] ?? '') === '9.90');

// 伪造签名
$fake = $notify;
$fake['sign'] = base64_encode(random_bytes(256));
check('伪造签名被拒绝', $gw->verifyNotify($fake)['ok'] === false);

// 篡改金额
$badAmount = $notify;
$badAmount['total_amount'] = '0.01';
$badAmount['sign'] = AlipaySign::sign(
    array_diff_key($badAmount, ['sign' => 1, 'sign_type' => 1]),
    $kp2['private']  // 用别人的私钥签
);
check('他人私钥签名的通知被拒绝', $gw->verifyNotify($badAmount)['ok'] === false);

// 非成功状态
$pending = $notify;
$pending['trade_status'] = 'WAIT_BUYER_PAY';
$pending['sign'] = AlipaySign::sign(
    array_diff_key($pending, ['sign' => 1, 'sign_type' => 1]),
    $kp['private']
);
$rp = $gw->verifyNotify($pending);
check('未付款状态被标记为 pending', $rp['ok'] === false && !empty($rp['pending']), $rp['msg']);

// app_id 不匹配
$mismatch = $notify;
$mismatch['app_id'] = '9999999999999999';
$mismatch['sign'] = AlipaySign::sign(
    array_diff_key($mismatch, ['sign' => 1, 'sign_type' => 1]),
    $kp['private']
);
check('app_id 不匹配被拒绝', $gw->verifyNotify($mismatch)['ok'] === false);

// 仅支持 RSA2，拒绝 RSA
$rsa1 = $notify;
$rsa1['sign_type'] = 'RSA';
check('拒绝 RSA（非 RSA2）签名类型', AlipaySign::verify($rsa1, $kp['public']) === false);

// ---------- 9. 未配置时的错误处理 ----------
echo "\n[9] 未配置支付宝时的行为\n";
$gwEmpty = new AlipayGateway(['app_id'=>'','private_key'=>'','public_key'=>'','gateway'=>'sandbox']);
try {
    $gwEmpty->precreate('x', 'y', '1.00');
    check('未配置时抛出异常', false);
} catch (\RuntimeException $e) {
    check('未配置时抛出明确异常', strpos($e->getMessage(), 'APPID') !== false, $e->getMessage());
}

echo "\n===== 测试结果 =====\n";
echo "通过: $pass   失败: $fail\n\n";
exit($fail > 0 ? 1 : 0);
