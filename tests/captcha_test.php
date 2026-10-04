<?php
/**
 * 1.5.3 图形验证码测试
 *
 * 覆盖：
 *   - Captcha 类单元：生成位数/字符集、hash 存储、一次性、过期、不区分大小写
 *   - GD 图片：PNG 签名、可解析、尺寸
 *   - HTTP：captcha 端点输出（PNG + no-store）、sendCode 缺码/错码被拒、注册页展示
 *   - 开关：captcha_enabled 保存后注册页与 sendCode 行为联动（HTTP 管理端保存，
 *          避免 php -S 单进程 Setting 静态缓存污染）
 *
 * 运行前提：先启动本地 HTTP 服务：
 *   php -S 127.0.0.1:8099 -t <项目根目录>
 * 可用环境变量 LY_TEST_BASE 覆盖目标地址。
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Captcha\Captcha;
use App\Models\Admin;
use App\Models\Setting;

$BASE = getenv('LY_TEST_BASE') ?: 'http://127.0.0.1:8099';
$USER = 'adm_' . bin2hex(random_bytes(3));
$PASS = 'Captcha@2026';
$pass = 0; $fail = 0;
function chk(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] {$name}\n"; }
    else { $fail++; echo "[FAIL] {$name} {$extra}\n"; }
}

/* ---------- HTTP 帮助函数 ---------- */
function httpGet(string $url, string &$jar): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return "HTTP {$code}\n" . (string)$raw;
}
function httpGetBody(string $url, string &$jar): string {
    $r = httpGet($url, $jar);
    $p = strpos($r, "\r\n\r\n");
    return $p === false ? '' : substr($r, $p + 4);
}
function httpPost(string $url, array $data, string &$jar): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    echo "      [http POST " . parse_url($url, PHP_URL_QUERY) . " -> {$code}" . ($loc ? " loc={$loc}" : '') . "]\n";
    return (string)$body;
}
function csrfFrom(string $html): string {
    preg_match('/name="_token" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}
function settingRaw(string $k): string {
    $row = \App\Database::instance()->first('SELECT v FROM ly_settings WHERE `k`=? LIMIT 1', [$k]);
    return (string)($row['v'] ?? '');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/* ---------- 前置：建临时管理员并登录，经 HTTP 管理端写好配置 ---------- */
$jar = sys_get_temp_dir() . "/cap_" . uniqid() . ".jar";
$adminId = Admin::create($USER, $PASS, '图形码回归');
chk('创建临时管理员', $adminId > 0);
$loginPage = httpGetBody($BASE . '/index.php?r=admin/auth/login', $jar);
$loginToken = csrfFrom($loginPage);
chk('登录页取到 CSRF', $loginToken !== '');
$loginResp = httpPost($BASE . '/index.php?r=admin/auth/login', [
    '_token' => $loginToken, 'username' => $USER, 'password' => $PASS,
], $jar);
chk('管理员登录成功(302->admin/index)', strpos($loginResp, 'r=admin/auth/login') === false || $loginResp === '', substr($loginResp, 0, 100));
$setPage = httpGetBody($BASE . '/index.php?r=admin/setting/index&tab=mail', $jar);
$setToken = csrfFrom($setPage);
chk('设置页取到 CSRF', $setToken !== '');

// 保存 mail 表单：开启 SMTP（假配置，仅让 emailVerifyEnforced 成立；不会真实发信）
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $setToken, 'group' => 'mail',
    'smtp_enabled' => '1', 'smtp_host' => 'smtp.qq.com', 'smtp_port' => '465',
    'smtp_encryption' => 'ssl', 'smtp_username' => 'captcha_test@qq.com',
    'smtp_password' => 'fake-auth-code', 'smtp_from_email' => 'captcha_test@qq.com',
    'smtp_from_name' => '图形码测试', 'smtp_timeout' => '10',
], $jar);

echo "== 一、Captcha 单元 ==\n";

// 保存 reg 表单：验证码 + 图形码均开启
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $setToken, 'group' => 'reg',
    'register_email_verify' => '1', 'register_email_domains' => 'qq.com,foxmail.com',
    'captcha_enabled' => '1',
], $jar);
chk('captcha_enabled=1 已写入', settingRaw('captcha_enabled') === '1');
chk('smtp_enabled=1 已写入（mail 组互不干扰）', settingRaw('smtp_enabled') === '1');

// 1 生成位数与字符集
$code = Captcha::issue();
chk('生成 4 位明文（无易混淆字符）', (bool)preg_match('/^[2-9a-hjkmnp-z]{4}$/', $code), "code={$code}");

// 2 session 只存哈希
$st = $_SESSION['_captcha_v1'] ?? null;
chk('Session 存哈希而非明文', is_array($st) && isset($st['hash']) && strpos(json_encode($st), $code) === false);

// 3 大小写不敏感
chk('校验不区分大小写', Captcha::verify(strtoupper($code)) === true);

// 4 一次性：第二次同值必须失败
chk('一次性：复用即失败', Captcha::verify($code) === false);

// 5 错误码
Captcha::issue();
chk('错误码被拒', Captcha::verify($code === 'aaaa' ? 'zzzz' : 'aaaa') === false);

// 6 过期
Captcha::issue();
$_SESSION['_captcha_v1']['expires'] = time() - 1;
chk('过期即拒', Captcha::verify($code) === false);

// 7 空输入 / 非法字符
Captcha::issue();
chk('空输入被拒', Captcha::verify('') === false);
chk('非法字符被拒', Captcha::verify('1!@#') === false);

echo "== 二、GD 图片 ==\n";

$code2 = Captcha::issue();
$png = Captcha::renderImage($code2);
chk('PNG 签名正确', substr($png, 0, 8) === "\x89PNG\r\n\x1a\n");
$im = imagecreatefromstring($png);
chk('图片可被 GD 解析', $im !== false);
chk('尺寸 150x46', $im !== false && imagesx($im) === 150 && imagesy($im) === 46);
if ($im) imagedestroy($im);

echo "== 三、HTTP 端点 ==\n";

$resp = httpGet($BASE . '/index.php?r=auth/captcha', $jar);
chk('captcha 返回 200', strpos($resp, 'HTTP 200') === 0, substr($resp, 0, 40));
chk('Content-Type 为 image/png', stripos($resp, 'Content-Type: image/png') !== false);
chk('no-store 防缓存头', stripos($resp, 'no-store') !== false);
$body = httpGetBody($BASE . '/index.php?r=auth/captcha', $jar);
chk('输出为合法 PNG', substr($body, 0, 8) === "\x89PNG\r\n\x1a\n");

// 注册页展示图形验证码（needCode + needCaptcha 均开）
$regHtml = httpGetBody($BASE . '/index.php?r=auth/register', $jar);
chk('注册页含图形验证码图片', strpos($regHtml, 'captcha-img') !== false);

$regCsrf = csrfFrom($regHtml);
chk('注册页取到 CSRF', $regCsrf !== '');

// sendCode 缺图形码被拒
$r1 = httpPost($BASE . '/index.php?r=auth/sendCode', [
    '_token' => $regCsrf, 'email' => 'captest' . random_int(1000, 9999) . '@qq.com',
], $jar);
chk('缺图形码被拒', strpos($r1, '请输入图形验证码') !== false, substr($r1, 0, 120));

// sendCode 错图形码被拒
$r2 = httpPost($BASE . '/index.php?r=auth/sendCode', [
    '_token' => $regCsrf, 'email' => 'captest' . random_int(1000, 9999) . '@qq.com', 'captcha' => 'xxxx',
], $jar);
chk('错图形码被拒(提示换一张)', strpos($r2, '图形验证码不正确') !== false || strpos($r2, '换一张') !== false, substr($r2, 0, 120));

echo "== 四、开关联动（HTTP 管理端保存，防单进程缓存污染）==\n";

// 保存 reg 表单：captcha_enabled 不提交 = 关闭（未勾选补 0），verify=1 一起提交验证互不干扰
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $setToken, 'group' => 'reg',
    'register_email_verify' => '1',
    'register_email_domains' => 'qq.com,foxmail.com',
], $jar);
chk('captcha_enabled 已保存为 0', settingRaw('captcha_enabled') === '0', 'raw=' . settingRaw('captcha_enabled'));
chk('register_email_verify 保持 1（互不干扰）', settingRaw('register_email_verify') === '1');
chk('smtp_enabled 不受 reg 保存影响', settingRaw('smtp_enabled') === '1');

$regHtml2 = httpGetBody($BASE . '/index.php?r=auth/register', $jar);
chk('开关关闭后注册页不含图形码', strpos($regHtml2, 'captcha-img') === false);

// 关闭后 sendCode 越过图形码检查：用非法邮箱证明已走到 email 校验阶段
$r3 = httpPost($BASE . '/index.php?r=auth/sendCode', [
    '_token' => $regCsrf, 'email' => 'not-an-email',
], $jar);
chk('关闭后 sendCode 越过图形码检查', strpos($r3, '图形验证码') === false && strpos($r3, '邮箱') !== false, substr($r3, 0, 120));

// 恢复开启
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $setToken, 'group' => 'reg',
    'register_email_verify' => '1',
    'register_email_domains' => 'qq.com,foxmail.com',
    'captcha_enabled' => '1',
], $jar);
chk('captcha_enabled 已恢复为 1', settingRaw('captcha_enabled') === '1');
$regHtml3 = httpGetBody($BASE . '/index.php?r=auth/register', $jar);
chk('恢复后注册页重新展示图形码', strpos($regHtml3, 'captcha-img') !== false);

// 清理：还原测试期间写入的设置与临时管理员（SMTP 假配置也清掉）
foreach (['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
          'smtp_password', 'smtp_from_email', 'smtp_from_name', 'smtp_timeout'] as $k) {
    \App\Database::instance()->delete('ly_settings', '`k`=?', [$k]);
}
\App\Database::instance()->delete('ly_admins', 'id=?', [$adminId]);

echo "\n==== 图形验证码测试: $pass 通过 / $fail 失败 ====\n";
exit($fail > 0 ? 1 : 0);
