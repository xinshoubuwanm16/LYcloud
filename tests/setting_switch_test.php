<?php
/**
 * 1.5.0 设置开关互踩回归测试
 *
 * 背景：后台「邮件设置」页签下有两个独立表单（SMTP 邮件配置 / 注册与邮箱限制）。
 * 修复前两者共用 group=mail，保存任一表单都会把另一个表单未提交的开关补 0，
 * 表现为「开启注册邮箱验证码后，邮件服务被自动关闭」。
 * 修复后：group=mail 只管 smtp_enabled，group=reg 只管 register_email_verify。
 *
 * 运行前提：先启动本地 HTTP 服务：
 *   php -S 127.0.0.1:8099 -t <项目根目录>
 * 可用环境变量 LY_TEST_BASE 覆盖目标地址。
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Models\Admin;
use App\Models\Setting;

$BASE = getenv('LY_TEST_BASE') ?: 'http://127.0.0.1:8099';
$USER = 'adm_' . bin2hex(random_bytes(3));
$PASS = 'BugFix@2026';
$pass = 0; $fail = 0;
function chk(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] {$name}\n"; }
    else { $fail++; echo "[FAIL] {$name} {$extra}\n"; }
}

/* ---------- HTTP 帮助函数（cookie + CSRF） ---------- */
function httpGet(string $url, string &$jar): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return (string)$body;
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
    echo "      [http POST " . parse_url($url, PHP_URL_QUERY) . " -> {$code}"
        . ($loc ? " loc={$loc}" : '') . "]\n";
    return (string)$body;
}
function csrfFrom(string $html): string {
    preg_match('/name="_token" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

$jar = sys_get_temp_dir() . "/regbug_" . uniqid() . ".jar";

/** 直读数据库（绕过 CLI 进程内 Setting 静态缓存，反映 HTTP 端真实写入） */
function settingRaw(string $k): string {
    $row = \App\Database::instance()->first('SELECT v FROM ly_settings WHERE `k`=? LIMIT 1', [$k]);
    return (string)($row['v'] ?? '');
}

/* ---------- 建临时管理员并登录 ---------- */
$adminId = Admin::create($USER, $PASS, '互踩Bug回归');
chk('创建临时管理员', $adminId > 0);

$page  = httpGet($BASE . '/index.php?r=admin/auth/login', $jar);
$token = csrfFrom($page);
chk('后台登录页取到 CSRF', $token !== '');

httpPost($BASE . '/index.php?r=admin/auth/login', [
    '_token' => $token, 'username' => $USER, 'password' => $PASS,
], $jar);
$inside = httpGet($BASE . '/index.php?r=admin/index', $jar);
chk('登录成功（可访问控制台）', strpos($inside, 'admin-content') !== false || strpos($inside, '控制台') !== false);

/* ---------- 还原已知起点 ---------- */
Setting::set('smtp_enabled', '0');
Setting::set('register_email_verify', '1');

/* ---------- 场景 1（用户报告的 bug 场景）----------
 * 管理员先开启邮件服务，再在「注册与邮箱限制」表单保存验证码开关
 * 修复前：smtp_enabled 被连带清 0 → "邮件服务自动关了"          */
$token = csrfFrom(httpGet($BASE . '/index.php?r=admin/setting/index&tab=mail', $jar));
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $token, 'group' => 'mail',
    'smtp_enabled' => '1', 'smtp_host' => 'smtp.qq.com', 'smtp_port' => '465',
    'smtp_encryption' => 'ssl', 'smtp_username' => 'bugfix@qq.com',
    'smtp_password' => 'dummyauthcode16', 'smtp_from_email' => 'bugfix@qq.com',
    'smtp_from_name' => 'LY云计算', 'smtp_timeout' => '15',
], $jar);
chk('SMTP 表单保存后 smtp_enabled=1', settingRaw('smtp_enabled') === '1');
chk('SMTP 表单保存后密码已写入', settingRaw('smtp_password') !== '');

httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $token, 'group' => 'reg',
    'register_email_verify' => '1', 'register_email_domains' => 'qq.com,foxmail.com',
], $jar);
chk('★ 验证码表单保存后 register_email_verify=1', settingRaw('register_email_verify') === '1');
chk('★ Bug 回归：验证码表单保存后 smtp_enabled 仍为 1', settingRaw('smtp_enabled') === '1', '实际=' . settingRaw('smtp_enabled'));
chk('★ 域名白名单已保存', strpos(settingRaw('register_email_domains'), 'foxmail.com') !== false);

/* ---------- 场景 2：关闭验证码也不影响邮件服务 ---------- */
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $token, 'group' => 'reg',
    'register_email_domains' => 'qq.com',
], $jar); // 模拟"未勾选/选了关闭"：开关不在 POST
chk('★ 关闭验证码（缺省补0）后 smtp_enabled 仍为 1', settingRaw('smtp_enabled') === '1', '实际=' . settingRaw('smtp_enabled'));
chk('★ 缺省提交时 register_email_verify 补 0', settingRaw('register_email_verify') === '0');
chk('★ 域名白名单同步更新', settingRaw('register_email_domains') === 'qq.com');

/* ---------- 场景 3：保存 SMTP 表单也不影响验证码开关 ---------- */
Setting::set('register_email_verify', '1'); // 模拟已有状态
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $token, 'group' => 'mail',
    'smtp_enabled' => '1', 'smtp_host' => 'smtp.163.com', 'smtp_port' => '465',
    'smtp_encryption' => 'ssl', 'smtp_username' => 'bugfix@163.com',
    'smtp_password' => '', 'smtp_from_email' => 'bugfix@163.com', 'smtp_timeout' => '15',
], $jar);
chk('★ SMTP 表单保存（密码留空）后验证码开关保持 1', settingRaw('register_email_verify') === '1', '实际=' . settingRaw('register_email_verify'));
chk('★ SMTP 密码留空不被清空', settingRaw('smtp_password') === 'dummyauthcode16');
chk('★ SMTP host 已更新', settingRaw('smtp_host') === 'smtp.163.com');

/* ---------- 场景 4：关闭邮件服务不影响验证码开关 ---------- */
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $token, 'group' => 'mail',
    'smtp_enabled' => '0', 'smtp_host' => 'smtp.163.com', 'smtp_port' => '465',
    'smtp_encryption' => 'ssl', 'smtp_username' => 'bugfix@163.com',
    'smtp_from_email' => 'bugfix@163.com', 'smtp_timeout' => '15',
], $jar);
chk('关闭邮件服务后 smtp_enabled=0', settingRaw('smtp_enabled') === '0');
chk('★ 关闭邮件服务后验证码开关保持 1', settingRaw('register_email_verify') === '1', '实际=' . settingRaw('register_email_verify'));

/* ---------- 还原出厂 ---------- */
Setting::set('smtp_enabled', '0');
Setting::set('smtp_host', '');
Setting::set('smtp_username', '');
Setting::set('smtp_password', '');
Setting::set('smtp_from_email', '');
Setting::set('register_email_verify', '1');
Setting::set('register_email_domains', 'qq.com,foxmail.com');

/* ---------- 清理临时管理员 ---------- */
$db = \App\Database::instance();
$db->delete('ly_admins', 'id=?', [$adminId]);
@unlink($jar);

echo "\n==== 互踩 Bug 回归: {$pass} 通过 / {$fail} 失败 ====\n";
exit($fail > 0 ? 1 : 0);
