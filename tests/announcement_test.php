<?php
/**
 * 网站公告测试（1.6.1）
 *
 * 覆盖：
 *  - 后台「网站设置」保存公告（HTTP 表单提交 → 落库）
 *  - 前台首页渲染公告条：内容、关闭按钮、sessionStorage 记忆脚本
 *  - 内容 hash 与公告内容一一对应（关闭记忆的依据）
 *  - XSS 转义：恶意内容不会产生裸 HTML/JS，仅以转义形态出现
 *  - 公告更新 → hash 随之变化（访客已关闭的公告更新后自动重新显示）
 *  - 空公告 → 公告条与关闭脚本完全不渲染
 *  - 结束恢复出厂默认公告
 *
 * 运行：需先启动本地 HTTP 服务（php -S 127.0.0.1:8099 -t .），
 *       LY_TEST_BASE=http://127.0.0.1:8099 php tests/announcement_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Admin;

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
/** 直查 DB 读设置（规避 CLI 进程 Setting::$cache 固化问题） */
$dbVal = function (string $k) use ($db): string {
    return (string)($db->value('SELECT v FROM ly_settings WHERE k = ?', [$k]) ?? '');
};

/* ---------- 前置：HTTP 管理端登录 ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$USER = 'ann_admin_' . substr(md5(uniqid('', true)), 0, 8);
$PASS = 'Ann' . random_int(100000, 999999);
$jar  = sys_get_temp_dir() . "/ann_" . uniqid() . ".jar";
$adminId = Admin::create($USER, $PASS, '公告测试');
$chk('创建临时管理员', $adminId > 0);

$loginPage = httpGet($BASE . '/index.php?r=admin/auth/login', $jar);
preg_match('/name="_token" value="([^"]+)"/', $loginPage, $m);
$token = $m[1] ?? '';
$chk('登录页取到 CSRF', $token !== '');
httpPost($BASE . '/index.php?r=admin/auth/login', ['_token' => $token, 'username' => $USER, 'password' => $PASS], $jar);

/** 后台保存设置（每次重新抓设置页 CSRF，仅提交给定键，不影响其他键） */
$saveSettings = function (array $kv) use ($BASE, $jar): array {
    $setPage = httpGet($BASE . '/index.php?r=admin/setting/index', $jar);
    preg_match('/name="_token" value="([^"]+)"/', $setPage, $m);
    $post = array_merge(['_token' => $m[1] ?? ''], $kv);
    $ch = curl_init($BASE . '/index.php?r=admin/setting/save');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, $loc];
};

$home = function () use ($BASE): string {
    return httpGet($BASE . '/index.php?r=home/index');
};

$ANN_DEFAULT = '新站上线，全场特惠，支付后自动发货！';

/* ============ 第一节：保存公告并渲染 ============ */
echo "\n[1] 保存公告并前台渲染\n";
$ANN1 = '全场限时 8 折，新用户注册即送 10 元优惠券';
[$code1, $loc1] = $saveSettings(['site_announce' => $ANN1]);
$chk('保存公告返回 302 重定向', $code1 === 302, "code={$code1} loc={$loc1}");
$chk('公告内容已落库', $dbVal('site_announce') === $ANN1, $dbVal('site_announce'));

$html = $home();
$chk('首页渲染弹窗（announceModal）', strpos($html, 'announceModal') !== false);
$chk('首页渲染公告文本', strpos($html, $ANN1) !== false);
$chk('首页渲染关闭按钮（am-close）', strpos($html, 'am-close') !== false);
$chk('首页渲染关闭记忆脚本（ly_announce_closed）', strpos($html, 'ly_announce_closed') !== false);
$chk('关闭按钮携带当前内容 hash', strpos($html, "LYAnn.close('" . md5($ANN1) . "')") !== false);

/* ============ 第二节：XSS 转义 ============ */
echo "\n[2] XSS 转义\n";
$XSS = '<script>alert("x")</script><b onmouseover=alert(1)>X</b>';
$saveSettings(['site_announce' => $XSS]);
$html = $home();
$chk('无裸 <script>alert 注入', stripos($html, '<script>alert') === false);
$chk('无裸 <b onmouseover 注入', stripos($html, '<b onmouseover') === false);
$chk('恶意内容以转义形态出现（&lt;script&gt;）', strpos($html, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;') !== false);
$chk('转义形态下关闭按钮仍正常渲染', strpos($html, 'am-close') !== false);
$chk('hash 按原始恶意内容计算（记忆一致性）', strpos($html, "LYAnn.close('" . md5($XSS) . "')") !== false);

/* ============ 第三节：公告更新 → hash 变化 ============ */
echo "\n[3] 公告更新后关闭记忆自动失效\n";
$oldHash = md5($XSS);
$ANN2 = '系统将于今晚 02:00-03:00 例行维护，期间暂停发货';
$saveSettings(['site_announce' => $ANN2]);
$html = $home();
$chk('新公告文本渲染', strpos($html, $ANN2) !== false);
$chk('旧内容 hash 已消失', strpos($html, $oldHash) === false);
$chk('新内容 hash 出现（所有访客将重新看到公告）', strpos($html, "LYAnn.close('" . md5($ANN2) . "')") !== false);

/* ============ 第四节：空公告不渲染 ============ */
echo "\n[4] 空公告\n";
[$code2] = $saveSettings(['site_announce' => '']);
$chk('清空公告返回 302', $code2 === 302, "code={$code2}");
$chk('空值已落库', $dbVal('site_announce') === '');
$html = $home();
$chk('弹窗与横条均不渲染（横条已废弃）', strpos($html, 'announce-bar') === false);
$chk('首页不再渲染关闭脚本', strpos($html, 'ly_announce_closed') === false);

/* ============ 清理：恢复默认公告 + 删除临时管理员 ============ */
echo "\n[5] 清理\n";
$saveSettings(['site_announce' => $ANN_DEFAULT]);
$chk('恢复默认公告', $dbVal('site_announce') === $ANN_DEFAULT, $dbVal('site_announce'));
$db->delete('ly_admins', 'id = ?', [$adminId]);
$chk('清理临时管理员', $db->value('SELECT COUNT(*) FROM ly_admins WHERE id = ?', [$adminId]) == 0);

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "公告测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
