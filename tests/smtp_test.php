<?php
/**
 * SMTP 邮件客户端测试
 *
 * 覆盖：配置读取、MIME 头编码（中文/折行/UTF-8 完整性）、报文组装、
 *      响应码解析、以及"发信失败绝不抛异常"的降级保证。
 *
 * 用法：php tests/smtp_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Mail\Mailer;
use App\Mail\MailService;
use App\Models\Setting;

$pass = 0;
$fail = 0;
$group = '';

function group($name)
{
    global $group;
    $group = $name;
    echo "\n[" . $name . "]\n";
}

function check($desc, $cond)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✔ " . $desc . "\n";
    } else {
        $fail++;
        echo "  ✘ " . $desc . "   <-- 失败\n";
    }
}

echo "==========================================\n";
echo " LY云计算 · SMTP 邮件客户端测试\n";
echo "==========================================\n";

// ------------------------------------------------------------
group('1. 配置读取');
$cfg = Setting::smtp();
check('smtp() 返回数组', is_array($cfg));
foreach (['enabled', 'host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name', 'timeout'] as $k) {
    check('包含配置键 ' . $k, array_key_exists($k, $cfg));
}
check('port 为整数', is_int($cfg['port']));
check('timeout 为整数', is_int($cfg['timeout']));
check('encryption 默认值合法', in_array($cfg['encryption'], ['ssl', 'tls', 'none'], true));

group('2. smtpConfigured() 判定');
$origEnabled = Setting::get('smtp_enabled');
$origHost    = Setting::get('smtp_host');
$origUser    = Setting::get('smtp_username');
$origPass    = Setting::get('smtp_password');
$origFrom    = Setting::get('smtp_from_email');

Setting::set('smtp_enabled', '0');
Setting::set('smtp_host', 'smtp.qq.com');
Setting::set('smtp_username', 'a@qq.com');
Setting::set('smtp_password', 'x');
Setting::set('smtp_from_email', 'a@qq.com');
check('开关关闭时 -> false', Setting::smtpConfigured() === false);

Setting::set('smtp_enabled', '1');
Setting::set('smtp_host', '');
check('缺少 host -> false', Setting::smtpConfigured() === false);

Setting::set('smtp_host', 'smtp.qq.com');
Setting::set('smtp_username', '');
check('缺少 username -> false', Setting::smtpConfigured() === false);

Setting::set('smtp_username', 'a@qq.com');
Setting::set('smtp_password', '');
check('缺少 password -> false', Setting::smtpConfigured() === false);

Setting::set('smtp_password', 'x');
Setting::set('smtp_from_email', 'not-an-email');
check('发件人邮箱非法 -> false', Setting::smtpConfigured() === false);

Setting::set('smtp_from_email', 'a@qq.com');
check('字段齐全且开启 -> true', Setting::smtpConfigured() === true);

// ------------------------------------------------------------
group('3. MIME 头编码（中文主题 / RFC2047）');
$ascii = 'Hello World';
check('纯 ASCII 不变形', Mailer::encodeHeader($ascii) === $ascii);

$cn = '邮箱验证码';
$enc = Mailer::encodeHeader($cn);
check('中文被编码为 =?UTF-8?B?...?=', strpos($enc, '=?UTF-8?B?') === 0 && substr($enc, -2) === '?=');
check('中文可解码还原', iconv_mime_decode($enc, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') === $cn);

$long = '【LY云计算】您的面板已开通 - 20261001150457170070 这是一段特意写得很长的中文主题用于测试折行是否会把 UTF-8 字符截断导致乱码';
$encLong = Mailer::encodeHeader($long);
check('超长中文主题发生折行', strpos($encLong, "\r\n ") !== false);
$decodedLong = iconv_mime_decode($encLong, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
check('超长主题解码完全一致（UTF-8 未被截断）', $decodedLong === $long);

$mixed = 'LY云计算 IPv6 宝塔面板 Host';
check('中英混排可还原', iconv_mime_decode(Mailer::encodeHeader($mixed), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') === $mixed);

check('空字符串返回空', Mailer::encodeHeader('') === '');

// 每个 encoded-word 不超过 75 字符
$maxWord = 0;
foreach (preg_split('/\r\n\s+/', $encLong) as $w) {
    $maxWord = max($maxWord, strlen($w));
}
check('单个 encoded-word <= 75 字符（' . $maxWord . '）', $maxWord <= 75);

// ------------------------------------------------------------
group('4. HTML 转纯文本');
$html = '<p>第一段</p><p>第二段<br>换行</p><script>var x=1;</script>';
$txt = Mailer::htmlToText($html);
check('剥离 script 内容', strpos($txt, 'var x') === false);
check('保留正文文字', strpos($txt, '第一段') !== false && strpos($txt, '第二段') !== false);
check('无残留标签', strpos($txt, '<') === false);
check('实体被解码', Mailer::htmlToText('&lt;b&gt;&amp;') === '<b>&');

// ------------------------------------------------------------
group('5. 响应码解析（私有方法经反射测试）');
$ref = new ReflectionClass(Mailer::class);
$m = $ref->getMethod('codeMatches');
$m->setAccessible(true);
check('250 匹配 250', $m->invoke(null, "250 OK\r\n", '250') === true);
check('多行续行 250- 也匹配', $m->invoke(null, "250-test\r\n250 OK\r\n", '250') === true);
check('535 不匹配 235', $m->invoke(null, "535 Login fail\r\n", '235') === false);
check('334 匹配 334', $m->invoke(null, "334 VXNlcm5hbWU6\r\n", '334') === true);
check('空响应不匹配', $m->invoke(null, '', '250') === false);
check('过短响应不匹配', $m->invoke(null, "25\r\n", '250') === false);

// ------------------------------------------------------------
group('6. 发信失败降级（绝不抛异常）');
$m2 = new Mailer([
    'enabled' => true, 'host' => '127.0.0.1', 'port' => 1, // 必然连不上
    'encryption' => 'none', 'username' => 'u@qq.com', 'password' => 'p',
    'from_email' => 'u@qq.com', 'from_name' => 'T', 'timeout' => 3,
]);
$threw = false;
$res = null;
try {
    $res = $m2->send('a@qq.com', '主题', '<p>正文</p>');
} catch (\Throwable $e) {
    $threw = true;
}
check('连接失败不抛异常', $threw === false);
check('连接失败返回 false', $res === false);
check('记录了 lastError', $m2->lastError() !== '');

$bad = new Mailer(['enabled' => true, 'host' => '', 'port' => 0, 'encryption' => 'none',
    'username' => '', 'password' => '', 'from_email' => '', 'from_name' => '', 'timeout' => 3]);
check('空配置返回 false 不抛异常', $bad->send('a@qq.com', 's', 'b') === false);

check('收件人格式非法返回 false', $m2->send('not-an-email', 's', 'b') === false);

// ------------------------------------------------------------
group('7. MailService 未配置时的降级');
Setting::set('smtp_enabled', '0');
check('configured() 为 false', MailService::configured() === false);

$t = false;
$r1 = $r2 = $r3 = null;
try {
    $r1 = MailService::sendVerifyCode('a@qq.com', '123456');
    $r2 = MailService::sendPasswordReset('a@qq.com', 'http://x/y', 30);
    $r3 = MailService::sendTest('a@qq.com');
} catch (\Throwable $e) {
    $t = true;
}
check('未配置时三类邮件均不抛异常', $t === false);
check('sendVerifyCode 返回 false', $r1 === false);
check('sendPasswordReset 返回 false', $r2 === false);
check('sendTest 返回 false', $r3 === false);
check('lastError 有说明', MailService::lastError() !== '');

// 发货邮件：订单不存在时应优雅失败
$r4 = MailService::sendDelivery(999999999);
check('订单不存在时返回 false 不抛异常', $r4 === false);

// ------------------------------------------------------------
group('8. 邮件模板内容');
$mailSvc = new ReflectionClass(MailService::class);
$mk = $mailSvc->getMethod('shell');
$mk->setAccessible(true);
$shell = $mk->invoke(null, '标题', '<p>内容</p>');
check('外壳含 DOCTYPE', strpos($shell, '<!DOCTYPE html>') !== false);
check('外壳声明 UTF-8', strpos($shell, 'charset="UTF-8"') !== false);
check('外壳含内容', strpos($shell, '<p>内容</p>') !== false);

$card = $mailSvc->getMethod('card');
$card->setAccessible(true);
$c = $card->invoke(null, '开通成功', '<p>正文</p>');
check('卡片含站点名', strpos($c, Setting::get('site_name', 'LY云计算')) !== false);
check('卡片含标题', strpos($c, '开通成功') !== false);

$kv = $mailSvc->getMethod('kvRow');
$kv->setAccessible(true);
$row = $kv->invoke(null, '面板账号', 'ly_root_s5');
check('KV 行含标签与值', strpos($row, '面板账号') !== false && strpos($row, 'ly_root_s5') !== false);

$esc = $mailSvc->getMethod('h');
$esc->setAccessible(true);
check('HTML 转义生效', $esc->invoke(null, '<script>') === '&lt;script&gt;');

// 恢复原配置
Setting::set('smtp_enabled', $origEnabled);
Setting::set('smtp_host', $origHost);
Setting::set('smtp_username', $origUser);
Setting::set('smtp_password', $origPass);
Setting::set('smtp_from_email', $origFrom);

echo "\n==========================================\n";
echo " 通过: {$pass}   失败: {$fail}\n";
echo "==========================================\n";

exit($fail === 0 ? 0 : 1);
