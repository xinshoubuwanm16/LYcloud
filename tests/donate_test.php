<?php
/**
 * 捐赠支付测试（1.7.9 新增）
 *
 * 覆盖：
 *   - 后台上传收款码：multipart 端到端（合法上传落库 + 旧图自动清理；
 *     未登录 / 非法 which / txt 伪扩展 / 超 2MB / 小于 50px 五类拒绝）
 *   - 设置保存：donate 组开关与说明（GROUP_SWITCHES 未勾选自动补 0）
 *   - Setting 辅助：donateEnabled / donateDesc / donateQr（非法 which、
 *     文件丢失返回空） / donateReady（开关 + 至少一张码）
 *   - 商品页默认选中：捐赠就绪时 radio 首位 checked、弹窗与双码渲染；
 *     未就绪时不渲染捐赠入口
 *   - 下单与支付页：channel=donate 落库、下单即占库存、支付页双码 +
 *     说明 + 「我已完成付款」；白名单外通道回落捐赠（create 与 pay 两条路径）
 *   - 声明付款：claimDonate 置位 / 重复幂等 / 非本人拒绝 / 非捐赠单拒绝
 *   - 后台核验：列表「已声明付款」徽标、详情核验卡、confirmDonate →
 *     markPaid 自动发货（DELIVERED + DONATE- 交易号 + 库存分配 + 销量），
 *     重复确认被幂等拒绝
 *   - 关闭驳回：close 释放库存；关闭后声明与确认均被状态门禁拒绝
 *   - 回归：易支付通道共存、demo 区块对捐赠单隐藏、已发货单访问支付页被跳转
 *   - 现场还原：订单/库存/商品/用户/管理员/图片/配置 全量清理 + 自检
 *
 * 用法：LY_TEST_BASE=http://127.0.0.1:8099 php tests/donate_test.php
 * 前置：站点服务已启动（php -S 127.0.0.1:8099 -t 项目根）；
 *       素材由本脚本自动生成于系统临时目录（需 python3 + PIL）。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;

$BASE = getenv('LY_TEST_BASE') ?: 'http://127.0.0.1:8099';
$db = Database::instance();

$pass = 0;
$fail = 0;
$TAG = 'DNT' . random_int(100000, 999999);

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
    curl_close($ch);
    return [$code, (string)$body];   // list($code, $body) 解构风格，与既有套件一致
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
    reloadSettings();   // HTTP 端可能写入配置（上传收款码/保存设置），刷新本进程缓存
    return ['code' => $code, 'body' => (string)$body, 'location' => $loc];
}

/** multipart 文件上传（收款码上传用） */
function httpPostFile(string $url, array $fields, array $files, string $jar = ''): array
{
    $post = $fields;
    foreach ($files as $field => $path) {
        $post[$field] = new CURLFile($path, '', basename($path));
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
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
    reloadSettings();   // HTTP 端可能写入配置（上传收款码/保存设置），刷新本进程缓存
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
 * 站点（HTTP 端）与测试脚本是两个 PHP 进程：HTTP 端上传收款码 / 保存设置
 * 只会写数据库，本进程 Setting::$cache 不会自动同步，必须显式重载。
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

function mkDonateProduct(Database $db, string $tag): int
{
    return (int)$db->insert('ly_products', [
        'name' => $tag, 'subtitle' => $tag, 'description' => $tag,
        'price' => '10.00', 'original_price' => '0.00',
        'cover' => '', 'tags' => '', 'spec' => '',
        'stock_mode' => 1, 'auto_deliver' => 1,
        'duration_value' => 0, 'duration_unit' => 'month',
        'limit_per_user' => 0,
        'sort' => 0, 'sales' => 0, 'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function mkDonateStock(Database $db, int $pid, string $tag): int
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

function mkDonateUser(Database $db, string $tag): array
{
    $email = $tag . random_int(10000, 99999) . '@qq.com';
    $id = (int)$db->insert('ly_users', [
        'email' => $email,
        'password' => password_hash('Test1234', PASSWORD_DEFAULT),
        'nickname' => $tag,
        'balance' => '0.00', 'status' => 1, 'email_verified' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return ['id' => $id, 'email' => $email];
}

/** 模型层直建订单（绕过控制器白名单，用于构造特定通道的订单） */
function mkDonateOrder(Database $db, int $uid, int $pid, string $channel): array
{
    $user = $db->first('SELECT * FROM ly_users WHERE id=?', [$uid]);
    $product = Product::find($pid);
    return Order::createWithStock($user, $product, $channel, false, null);
}

/** 前台用户登录（session cookie 落入 $jar） */
function loginFront(string $BASE, string $email, string $jar): bool
{
    [, $html] = httpGet($BASE . '/index.php?r=auth/login', $jar);
    $token = grabToken($html);
    httpPost($BASE . '/index.php?r=auth/login', [
        '_token' => $token, 'email' => $email, 'password' => 'Test1234',
    ], $jar);
    $c = httpGet($BASE . '/index.php?r=order/list', $jar);
    return (int)$c[0] === 200;
}

// ============================================================
// 0. 环境与素材
// ============================================================

$ASSET = sys_get_temp_dir() . '/dnt_assets_' . random_int(1000, 9999);
@mkdir($ASSET, 0755, true);

$pyCode = <<<'PY'
import os, sys
from PIL import Image
d = sys.argv[1]
Image.new('RGB', (300, 300), (16, 120, 220)).save(os.path.join(d, 'up_ali1.png'))
Image.new('RGB', (300, 300), (30, 170, 90)).save(os.path.join(d, 'up_ali2.png'))
Image.new('RGB', (300, 300), (60, 60, 70)).save(os.path.join(d, 'up_wechat1.png'))
Image.new('RGB', (30, 30), (200, 200, 200)).save(os.path.join(d, 'small.png'))
with open(os.path.join(d, 'fake.txt'), 'wb') as f:
    f.write(b'this is not an image at all')
with open(os.path.join(d, 'big.png'), 'wb') as f:
    f.write(os.urandom(2 * 1024 * 1024 + 200 * 1024))
print('ok')
PY;
file_put_contents($ASSET . '/gen.py', $pyCode);
exec('python3 ' . escapeshellarg($ASSET . '/gen.py') . ' ' . escapeshellarg($ASSET) . ' 2>&1', $pyOut, $pyRc);

group('0. 环境与素材');
check('测试素材生成（PIL：双码图 / 小图 / 伪扩展 / 超 2MB）',
    $pyRc === 0
    && is_file($ASSET . '/up_ali1.png')
    && is_file($ASSET . '/up_wechat1.png')
    && is_file($ASSET . '/small.png')
    && is_file($ASSET . '/fake.txt')
    && is_file($ASSET . '/big.png')
    && filesize($ASSET . '/big.png') > 2 * 1024 * 1024,
    implode(';', array_slice($pyOut, 0, 3)));

// ---------- 现场快照与备份（第 9 节还原） ----------
$SNAP_KEYS = ['donate_enabled', 'donate_desc', 'donate_qr_ali_path',
              'donate_qr_wechat_path', 'demo_pay_enabled', 'order_expire_min'];
$snap = [];
foreach ($SNAP_KEYS as $k) {
    $snap[$k] = (string)Setting::get($k, '');
}

$backupDir = $ASSET . '/site_backup';
@mkdir($backupDir, 0755, true);
$backup = [];   // [setting_key => ['rel' => 原相对路径, 'bak' => 备份绝对路径]]
foreach (['donate_qr_ali_path', 'donate_qr_wechat_path'] as $bk) {
    $rel = $snap[$bk];
    if ($rel !== '' && strpos($rel, '..') === false && is_file(LY_ROOT . '/' . $rel)) {
        $dst = $backupDir . '/' . basename($rel);
        @copy(LY_ROOT . '/' . $rel, $dst);
        $backup[$bk] = ['rel' => $rel, 'bak' => $dst];
    }
}
$donateDir = LY_ROOT . '/uploads/donate';
$preFiles = is_dir($donateDir) ? array_values(array_diff(scandir($donateDir), ['.', '..'])) : [];

check('现场快照与二维码备份', is_dir($backupDir) && count($snap) === 6);

// 测试期间防超时关单（expire_min 现场仅 5 分钟），第 9 节随快照还原
Setting::set('order_expire_min', '120');

// ============================================================
// 管理员与前台用户
// ============================================================

$ADMIN_USER = 'dnt_admin_' . substr(md5(uniqid('', true)), 0, 8);
$ADMIN_PASS = 'Dnt' . random_int(100000, 999999);
$adminJar = sys_get_temp_dir() . '/dnt_admin_' . random_int(10000, 99999) . '.jar';
$adminId = Admin::create($ADMIN_USER, $ADMIN_PASS, '捐赠测试');

[, $adminLoginHtml] = httpGet($BASE . '/index.php?r=admin/auth/login', $adminJar);
httpPost($BASE . '/index.php?r=admin/auth/login', [
    '_token' => grabToken($adminLoginHtml),
    'username' => $ADMIN_USER, 'password' => $ADMIN_PASS,
], $adminJar);
[, $chkList] = httpGet($BASE . '/index.php?r=admin/order/list', $adminJar);

$user1 = mkDonateUser($db, $TAG . '_u1');
$user2 = mkDonateUser($db, $TAG . '_u2');
$jar1 = sys_get_temp_dir() . '/dnt_user1_' . random_int(10000, 99999) . '.jar';
$jar2 = sys_get_temp_dir() . '/dnt_user2_' . random_int(10000, 99999) . '.jar';
$u1ok = loginFront($BASE, $user1['email'], $jar1);
$u2ok = loginFront($BASE, $user2['email'], $jar2);

/** 后台设置页 CSRF（donate 页签） */
$csrfSetting = function () use ($BASE, $adminJar): string {
    [, $html] = httpGet($BASE . '/index.php?r=admin/setting/index&tab=donate', $adminJar);
    return grabToken($html);
};
/** 后台订单详情页 CSRF */
$csrfOrder = function (int $oid) use ($BASE, $adminJar): string {
    [, $html] = httpGet($BASE . '/index.php?r=admin/order/detail&id=' . $oid, $adminJar);
    return grabToken($html);
};
/** 商品页 CSRF（下单表单；默认用户 jar，user2 断言时显式传 jar2） */
$csrfProduct = function (int $pid, ?string $jar = null) use ($BASE, $jar1): string {
    [, $html] = httpGet($BASE . '/index.php?r=product/show&id=' . $pid, $jar ?? $jar1);
    return grabToken($html);
};

// ============================================================
// 1. 后台上传收款码（HTTP 端到端）
// ============================================================

$uploadUrl = $BASE . '/index.php?r=admin/setting/uploadDonate';
$oldAli    = (string)Setting::get('donate_qr_ali_path', '');
$oldWechat = (string)Setting::get('donate_qr_wechat_path', '');

group('1. 后台上传收款码（HTTP 端到端）');

[, $settingPage] = httpGet($BASE . '/index.php?r=admin/setting/index&tab=donate', $adminJar);
check('临时管理员登录成功且捐赠页签可访问',
    strpos($settingPage, '捐赠收款码') !== false && strpos($settingPage, 'donate-qr-grid') !== false);

$r = httpPostFile($uploadUrl, ['which' => 'ali'], ['qr_ali' => $ASSET . '/up_ali1.png'], '');
check('未登录上传 → 302 踢回登录', (int)$r['code'] === 302, 'code=' . $r['code']);

$r = httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'hack'],
    ['qr_ali' => $ASSET . '/up_ali1.png'], $adminJar);
check('非法 which → 302 且配置不变',
    (int)$r['code'] === 302 && strpos($r['location'], 'tab=donate') !== false
    && (string)Setting::get('donate_qr_ali_path', '') === $oldAli,
    'code=' . $r['code']);

$r = httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'ali'],
    ['qr_ali' => $ASSET . '/fake.txt'], $adminJar);
check('txt 伪扩展拒绝（仅支持 JPG/PNG/WebP）',
    (int)$r['code'] === 302 && (string)Setting::get('donate_qr_ali_path', '') === $oldAli);

$r = httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'ali'],
    ['qr_ali' => $ASSET . '/small.png'], $adminJar);
check('30×30 小图拒绝（至少 50×50）',
    (int)$r['code'] === 302 && (string)Setting::get('donate_qr_ali_path', '') === $oldAli);

$r = httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'ali'],
    ['qr_ali' => $ASSET . '/big.png'], $adminJar);
check('2.2MB 超限拒绝（≤2MB）',
    (int)$r['code'] === 302 && (string)Setting::get('donate_qr_ali_path', '') === $oldAli);

$r = httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'ali'],
    ['qr_ali' => $ASSET . '/up_ali1.png'], $adminJar);
$newAli1 = (string)Setting::get('donate_qr_ali_path', '');
check('合法 PNG 上传成功 → 302 回捐赠页签',
    (int)$r['code'] === 302 && strpos($r['location'], 'tab=donate') !== false, 'code=' . $r['code']);
check('支付宝码路径写入配置且符合随机命名规则',
    preg_match('/^uploads\/donate\/qr_ali_[0-9a-f]{16}\.png$/', $newAli1) === 1, $newAli1);
check('新码文件真实落盘', $newAli1 !== '' && is_file(LY_ROOT . '/' . $newAli1));
check('旧支付宝码被自动清理',
    $oldAli === '' || !is_file(LY_ROOT . '/' . $oldAli), $oldAli);

$r = httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'wechat'],
    ['qr_wechat' => $ASSET . '/up_wechat1.png'], $adminJar);
$newWechat1 = (string)Setting::get('donate_qr_wechat_path', '');
check('微信码上传成功且文件落盘',
    preg_match('/^uploads\/donate\/qr_wechat_[0-9a-f]{16}\.png$/', $newWechat1) === 1
    && is_file(LY_ROOT . '/' . $newWechat1), $newWechat1);
check('旧微信码被自动清理',
    $oldWechat === '' || !is_file(LY_ROOT . '/' . $oldWechat), $oldWechat);

$r = httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'ali'],
    ['qr_ali' => $ASSET . '/up_ali2.png'], $adminJar);
$newAli2 = (string)Setting::get('donate_qr_ali_path', '');
check('再次上传支付宝码 → 配置更新且上一张被清理',
    $newAli2 !== $newAli1 && is_file(LY_ROOT . '/' . $newAli2) && !is_file(LY_ROOT . '/' . $newAli1));

$r = httpPostFile($BASE . '/index.php?r=admin/setting/removeDonate',
    ['_token' => $csrfSetting(), 'which' => 'wechat'], [], $adminJar);
check('移除微信码 → 配置清空且文件删除',
    (int)$r['code'] === 302
    && (string)Setting::get('donate_qr_wechat_path', '') === ''
    && !is_file(LY_ROOT . '/' . $newWechat1));

// 重新上传微信码，恢复「双码就绪」基线（后续各节依赖）
httpPostFile($uploadUrl, ['_token' => $csrfSetting(), 'which' => 'wechat'],
    ['qr_wechat' => $ASSET . '/up_wechat1.png'], $adminJar);
$newWechat2 = (string)Setting::get('donate_qr_wechat_path', '');
check('微信码重新上传 → 捐赠恢复就绪',
    preg_match('/^uploads\/donate\/qr_wechat_[0-9a-f]{16}\.png$/', $newWechat2) === 1
    && is_file(LY_ROOT . '/' . $newWechat2)
    && Setting::donateReady() === true, $newWechat2);

// ============================================================
// 2. 设置保存与 Setting 归一
// ============================================================

group('2. 设置保存与 Setting 归一');

$r = httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $csrfSetting(), 'group' => 'donate',
    'donate_enabled' => '1', 'donate_desc' => '捐赠支付测试说明',
], $adminJar);
check('保存 donate 组 → 302 回捐赠页签',
    (int)$r['code'] === 302 && strpos($r['location'], 'tab=donate') !== false, 'code=' . $r['code']);
check('donateEnabled() === true', Setting::donateEnabled() === true);
check('donateDesc() 落库', Setting::donateDesc() === '捐赠支付测试说明', Setting::donateDesc());

// GROUP_SWITCHES：表单未提交开关字段时自动补 0（防止越界改写）
httpPost($BASE . '/index.php?r=admin/setting/save', [
    '_token' => $csrfSetting(), 'group' => 'donate',
    'donate_desc' => '捐赠支付测试说明',
], $adminJar);
check('表单未勾选开关 → donate_enabled 自动补 0', (string)Setting::get('donate_enabled') === '0');
Setting::set('donate_enabled', '1');
check('重新开启捐赠', Setting::donateEnabled() === true);

check("donateQr('ali') 与配置一致", Setting::donateQr('ali') === $newAli2);
check("donateQr('wechat') 与配置一致", Setting::donateQr('wechat') === $newWechat2);
check("donateQr('ali') 指向真实存在的文件", is_file(LY_ROOT . '/' . Setting::donateQr('ali')));
check("donateQr('hack') 非法 which → 空串", Setting::donateQr('hack') === '');
check("donateQr('../hack') 穿越串 → 空串", Setting::donateQr('../hack') === '');
check("donateQr('') 空串 → 空串", Setting::donateQr('') === '');
check('donateReady() === true（开启 + 双码就绪）', Setting::donateReady() === true);

Setting::set('donate_enabled', '0');
check('关闭开关 → donateReady() 立即失效（即使码还在）',
    Setting::donateEnabled() === false && Setting::donateReady() === false);
Setting::set('donate_enabled', '1');

$aliKeep = $newAli2;
$wechatKeep = $newWechat2;
Setting::set('donate_qr_ali_path', 'uploads/donate/qr_ali_deleted_file.png');
check('码文件丢失 → donateQr 返回空串', Setting::donateQr('ali') === '');
check('单侧码丢失 → donateReady 仍就绪（微信兜底）', Setting::donateReady() === true);
Setting::set('donate_qr_wechat_path', '');
check('双侧码均无 → donateReady 失效', Setting::donateReady() === false);
Setting::set('donate_qr_ali_path', $aliKeep);
Setting::set('donate_qr_wechat_path', $wechatKeep);
check('恢复双码 → 就绪恢复', Setting::donateReady() === true && Setting::donateQr('ali') === $aliKeep);

// ============================================================
// 3. 商品页默认选中与弹窗
// ============================================================

$p1 = mkDonateProduct($db, $TAG . '_p1');
// 五个测试订单（A/hack 回落/qr 回落/C/B）下单即各占一条库存，另留一条缓冲
foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $sfx) {
    mkDonateStock($db, $p1, $TAG . '_' . $sfx);
}

group('3. 商品页默认选中与弹窗');
check('测试商品与 3 条库存就绪', $p1 > 0 && $u1ok && $u2ok);

[, $prodHtml] = httpGet($BASE . '/index.php?r=product/show&id=' . $p1, $jar1);
check('商品页渲染捐赠 radio 且默认选中（首位 checked）',
    strpos($prodHtml, 'value="donate" checked') !== false);
check('商品页渲染收款码弹窗 donateModal', strpos($prodHtml, 'donateModal') !== false);
check('弹窗含支付宝收款码图片', $newAli2 !== '' && strpos($prodHtml, basename($newAli2)) !== false);
check('弹窗含微信收款码图片', $newWechat2 !== '' && strpos($prodHtml, basename($newWechat2)) !== false);

Setting::set('donate_enabled', '0');
[, $prodHtml2] = httpGet($BASE . '/index.php?r=product/show&id=' . $p1, $jar1);
check('关闭捐赠 → 商品页不再渲染捐赠 radio 与弹窗',
    strpos($prodHtml2, 'value="donate"') === false && strpos($prodHtml2, 'donateModal') === false);
Setting::set('donate_enabled', '1');

// ============================================================
// 4. 下单与支付页渲染
// ============================================================

group('4. 下单与支付页渲染');

$createdOrders = [];   // 所有测试订单 id（第 9 节清理）
$oidA = 0; $oidHack = 0; $oidQr = 0; $oidC = 0; $oidB = 0;

$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token' => $csrfProduct($p1), 'product_id' => $p1,
    'pay_channel' => 'donate', 'use_balance' => '0',
], $jar1);
check('捐赠通道下单 → 302 跳支付页',
    (int)$r['code'] === 302 && preg_match('/[?&]id=(\d+)/', $r['location'], $m) === 1,
    'code=' . $r['code'] . ' loc=' . $r['location']);
$oidA = (int)($m[1] ?? 0);
$createdOrders[] = $oidA;

$rowA = $oidA > 0 ? $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidA]) : null;
check('订单落库：channel=donate / 待支付 / 未声明',
    $rowA !== null && (string)$rowA['pay_channel'] === 'donate'
    && (int)$rowA['status'] === Order::STATUS_PENDING
    && (int)$rowA['user_claimed'] === 0);
check('下单即占用库存（stock_id 已绑定）',
    $rowA !== null && (int)$rowA['stock_id'] > 0
    && (int)$db->value('SELECT status FROM ly_stocks WHERE id=?', [(int)$rowA['stock_id']]) === 1);

[, $payA] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidA, $jar1);
check('支付页 200 且渲染捐赠区块', (int)strlen($payA) > 0 && strpos($payA, 'donate-pay-area') !== false);
check('支付页渲染双码 grid 与两侧收款码', strpos($payA, 'dm-qr-grid') !== false
    && strpos($payA, 'alt="支付宝收款码"') !== false && strpos($payA, 'alt="微信收款码"') !== false);
check('支付页引用已上传的两张码',
    strpos($payA, basename($newAli2)) !== false && strpos($payA, basename($newWechat2)) !== false);
check('支付页展示收款说明', strpos($payA, '捐赠支付测试说明') !== false);
check('支付页有「我已完成付款」声明按钮',
    strpos($payA, '我已完成付款') !== false && strpos($payA, 'r=order/claimDonate') !== false);
check('demo 开启时捐赠支付页仍不渲染演示区块', strpos($payA, 'demo-pay-box') === false);

$r = httpPost($BASE . '/index.php?r=order/create', [
    '_token' => $csrfProduct($p1), 'product_id' => $p1,
    'pay_channel' => 'hack', 'use_balance' => '0',
], $jar1);
preg_match('/[?&]id=(\d+)/', $r['location'], $m2);
$oidHack = (int)($m2[1] ?? 0);
$createdOrders[] = $oidHack;
$rowHack = $oidHack > 0 ? $db->first('SELECT pay_channel FROM ly_orders WHERE id=?', [$oidHack]) : null;
check('白名单外通道下单 → 回落首位捐赠（create 路径）',
    $rowHack !== null && (string)$rowHack['pay_channel'] === 'donate');

$ordQr = mkDonateOrder($db, $user1['id'], $p1, 'qr');
$oidQr = (int)$ordQr['id'];
$createdOrders[] = $oidQr;
if (!Setting::alipayConfigured()) {
    // 官方支付宝未配置时 qr 不在白名单内，支付页应回落捐赠并改写订单通道
    [$pqCode, $payQr] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidQr, $jar1);
    $rowQr = $db->first('SELECT pay_channel FROM ly_orders WHERE id=?', [$oidQr]);
    check('失效通道订单访问支付页 → 回落捐赠并改写通道（pay 路径）',
        (int)$pqCode === 200
        && strpos($payQr, 'donate-pay-area') !== false
        && $rowQr !== null && (string)$rowQr['pay_channel'] === 'donate');
} else {
    // 官方支付宝已配置：qr 是合法通道，回归其正常渲染（无捐赠区块）
    [$pqCode, $payQr] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidQr, $jar1);
    check('官方 qr 通道订单支付页正常渲染（不受捐赠影响）',
        (int)$pqCode === 200
        && strpos($payQr, 'donate-pay-area') === false);
}

// ============================================================
// 5. 声明付款
// ============================================================

group('5. 声明付款');

$r = httpPost($BASE . '/index.php?r=order/claimDonate', ['id' => $oidA], '');
check('未登录声明 → 302 踢回登录', (int)$r['code'] === 302, 'code=' . $r['code']);

[, $payA2] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidA, $jar1);
$r = httpPost($BASE . '/index.php?r=order/claimDonate',
    ['id' => $oidA, '_token' => grabToken($payA2)], $jar1);
$rowA = $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidA]);
check('声明付款 → user_claimed=1 且 claim_at 落库',
    (int)$r['code'] === 302 && (int)$rowA['user_claimed'] === 1 && $rowA['claim_at'] !== null);
check('声明后订单仍为待支付（等待人工核验）', (int)$rowA['status'] === Order::STATUS_PENDING);

httpPost($BASE . '/index.php?r=order/claimDonate', ['id' => $oidA, '_token' => grabToken($payA2)], $jar1);
$rowA = $db->first('SELECT user_claimed, status FROM ly_orders WHERE id=?', [$oidA]);
check('重复声明幂等（状态保持已声明 + 待支付）',
    (int)$rowA['user_claimed'] === 1 && (int)$rowA['status'] === Order::STATUS_PENDING);

$r = httpPost($BASE . '/index.php?r=order/claimDonate',
    ['id' => $oidA, '_token' => $csrfProduct($p1, $jar2)], $jar2);
$rowA = $db->first('SELECT user_claimed FROM ly_orders WHERE id=?', [$oidA]);
check('非本人声明 → 拒绝且状态不变',
    (int)$r['code'] === 302 && (int)$rowA['user_claimed'] === 1);

// 非捐赠单：易支付通道订单（同时作为第 8 节 demo 对照）
$ordC = mkDonateOrder($db, $user2['id'], $p1, 'yipay_alipay');
$oidC = (int)$ordC['id'];
$createdOrders[] = $oidC;
$r = httpPost($BASE . '/index.php?r=order/claimDonate',
    ['id' => $oidC, '_token' => $csrfProduct($p1, $jar2)], $jar2);
$rowC = $db->first('SELECT user_claimed FROM ly_orders WHERE id=?', [$oidC]);
check('非捐赠通道订单声明 → 拒绝',
    (int)$r['code'] === 302 && $rowC !== null && (int)$rowC['user_claimed'] === 0);

// B 单：user1 的第二个捐赠单（未声明，第 7 节关闭驳回用）
$ordB = mkDonateOrder($db, $user1['id'], $p1, 'donate');
$oidB = (int)$ordB['id'];
$createdOrders[] = $oidB;
$rowB0 = $oidB > 0 ? $db->first('SELECT user_claimed, stock_id FROM ly_orders WHERE id=?', [$oidB]) : null;
$stockIdB = $rowB0 !== null ? (int)$rowB0['stock_id'] : 0;
check('B 单（未声明捐赠单）就绪',
    $rowB0 !== null && (int)$rowB0['user_claimed'] === 0 && $stockIdB > 0);

// ============================================================
// 6. 后台核验与自动发货
// ============================================================

group('6. 后台核验与自动发货');

$r = httpPost($BASE . '/index.php?r=admin/order/confirmDonate', ['order_id' => $oidA], '');
check('未登录确认收款 → 302 踢回登录', (int)$r['code'] === 302);

[, $listHtml] = httpGet($BASE . '/index.php?r=admin/order/list', $adminJar);
check('管理员登录可用（订单列表 200）', (int)$listHtml !== '' && strpos($listHtml, '订单') !== false);
check('列表页出现「已声明付款」徽标（待核验）', strpos($listHtml, '已声明付款') !== false);

[, $detailA] = httpGet($BASE . '/index.php?r=admin/order/detail&id=' . $oidA, $adminJar);
check('详情页渲染「捐赠收款核验」卡', strpos($detailA, '捐赠收款核验') !== false);
check('详情页有「确认收款并发货」表单',
    strpos($detailA, 'r=admin/order/confirmDonate') !== false && strpos($detailA, '确认收款并发货') !== false);
check('详情页显示买家已声明状态', strpos($detailA, '买家已声明完成付款') !== false);

$salesBefore = (int)Product::find($p1)['sales'];
$r = httpPost($BASE . '/index.php?r=admin/order/confirmDonate',
    ['order_id' => $oidA, '_token' => $csrfOrder($oidA)], $adminJar);
$rowA = $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidA]);
check('确认收款 → 302 回详情页', (int)$r['code'] === 302
    && strpos($r['location'], 'r=admin/order/detail') !== false, 'code=' . $r['code']);
check('订单自动发货：status=DELIVERED', $rowA !== null && (int)$rowA['status'] === Order::STATUS_DELIVERED);
check('交易号标记人工核验来源（DONATE- 前缀）',
    $rowA !== null && (string)$rowA['trade_no'] === 'DONATE-' . $rowA['order_no'],
    (string)($rowA['trade_no'] ?? ''));
check('delivered_at 落库', $rowA !== null && $rowA['delivered_at'] !== null);

$stockRow = $rowA !== null ? $db->first('SELECT * FROM ly_stocks WHERE id=?', [(int)$rowA['stock_id']]) : null;
check('库存分配给本订单（已售 + 绑定订单号）',
    $stockRow !== null && (int)$stockRow['status'] === 1 && (int)$stockRow['order_id'] === $oidA);
check('商品销量 +1', (int)Product::find($p1)['sales'] === $salesBefore + 1);

$tradeKeep = (string)$rowA['trade_no'];
httpPost($BASE . '/index.php?r=admin/order/confirmDonate',
    ['order_id' => $oidA, '_token' => $csrfOrder($oidA)], $adminJar);
$rowA = $db->first('SELECT status, trade_no FROM ly_orders WHERE id=?', [$oidA]);
check('重复确认被幂等拒绝（状态与交易号不变）',
    (int)$rowA['status'] === Order::STATUS_DELIVERED && (string)$rowA['trade_no'] === $tradeKeep);

[, $detailA2] = httpGet($BASE . '/index.php?r=admin/order/detail&id=' . $oidA, $adminJar);
check('已核验订单详情页不再出现确认表单',
    strpos($detailA2, 'r=admin/order/confirmDonate') === false);

// ============================================================
// 7. 关闭驳回路径
// ============================================================

group('7. 关闭驳回路径');

[, $detailB] = httpGet($BASE . '/index.php?r=admin/order/detail&id=' . $oidB, $adminJar);
check('未声明捐赠单详情提示「买家尚未声明付款」',
    strpos($detailB, '买家尚未声明付款') !== false);

$r = httpPost($BASE . '/index.php?r=admin/order/close',
    ['order_id' => $oidB, '_token' => $csrfOrder($oidB)], $adminJar);
$rowB = $db->first('SELECT * FROM ly_orders WHERE id=?', [$oidB]);
check('管理员关闭捐赠单 → 302 且 status=CLOSED',
    (int)$r['code'] === 302 && $rowB !== null && (int)$rowB['status'] === Order::STATUS_CLOSED);
check('关闭后 stock_id 清零', (int)$rowB['stock_id'] === 0);
$stockB = $db->first('SELECT status, order_id FROM ly_stocks WHERE id=?', [$stockIdB]);
check('库存已释放（未售 + 解绑订单）',
    $stockB !== null && (int)$stockB['status'] === 0 && (int)$stockB['order_id'] === 0);

$r = httpPost($BASE . '/index.php?r=order/claimDonate',
    ['id' => $oidB, '_token' => $csrfProduct($p1)], $jar1);
$rowB = $db->first('SELECT user_claimed, status FROM ly_orders WHERE id=?', [$oidB]);
check('已关闭订单声明付款 → 状态门禁拒绝',
    (int)$rowB['user_claimed'] === 0 && (int)$rowB['status'] === Order::STATUS_CLOSED);

httpPost($BASE . '/index.php?r=admin/order/confirmDonate',
    ['order_id' => $oidB, '_token' => $csrfOrder($oidB)], $adminJar);
$rowB = $db->first('SELECT status FROM ly_orders WHERE id=?', [$oidB]);
check('已关闭订单确认收款 → 门禁拒绝（status 保持 CLOSED）',
    (int)$rowB['status'] === Order::STATUS_CLOSED);

// ============================================================
// 8. 回归：既有通道与 demo 隔离
// ============================================================

group('8. 回归：既有通道与 demo 隔离');

[$payCCode, $payC] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidC, $jar2);
check('易支付订单支付页正常渲染（含 demo 区块）',
    $payCCode === 200 && strpos($payC, 'demo-pay-box') !== false,
    'code=' . $payCCode);
check('易支付支付页无捐赠区块与声明按钮',
    strpos($payC, 'donate-pay-area') === false && strpos($payC, '我已完成付款') === false);

[, $prodHtml3] = httpGet($BASE . '/index.php?r=product/show&id=' . $p1, $jar2);
check('商品页易支付通道与捐赠通道共存',
    strpos($prodHtml3, 'value="yipay_alipay"') !== false && strpos($prodHtml3, 'value="donate"') !== false);

Setting::set('demo_pay_enabled', '0');
[$payC2Code, $payC2] = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidC, $jar2);
check('关闭演示模式 → 易支付页不再渲染 demo 区块',
    $payC2Code === 200 && strpos($payC2, 'demo-pay-box') === false);
Setting::set('demo_pay_enabled', (string)$snap['demo_pay_enabled']);

$rPayA2 = httpGet($BASE . '/index.php?r=order/pay&id=' . $oidA, $jar1);
check('已发货捐赠单访问支付页 → 302 跳详情页',
    (int)$rPayA2[0] === 302 && strpos($rPayA2[1], 'donate-pay-area') === false,
    'code=' . $rPayA2[0]);

// ============================================================
// 9. 清理与自检
// ============================================================

group('9. 清理与自检');

// ---------- 配置还原（先于文件清理，保证语义正确） ----------
foreach ($snap as $k => $v) {
    Setting::set($k, $v);
}
foreach ($backup as $bk) {
    if (!is_file(LY_ROOT . '/' . $bk['rel']) && is_file($bk['bak'])) {
        @copy($bk['bak'], LY_ROOT . '/' . $bk['rel']);
    }
}
// 测试期间新产生的上传图（差集）删除
$postFiles = is_dir($donateDir) ? array_diff(scandir($donateDir), ['.', '..']) : [];
$removed = 0;
foreach ($postFiles as $f) {
    if (!in_array($f, $preFiles, true)) {
        @unlink($donateDir . '/' . $f);
        $removed++;
    }
}

// ---------- 数据清理 ----------
$ids = implode(',', array_filter(array_map('intval', $createdOrders)));
if ($ids !== '') {
    $db->delete('ly_orders', "id IN ($ids)");
}
$db->delete('ly_stocks', 'product_id=?', [$p1]);
$db->delete('ly_products', 'id=?', [$p1]);
$db->delete('ly_users', 'id IN (?,?)', [$user1['id'], $user2['id']]);
$db->delete('ly_admins', 'id=?', [$adminId]);
if (is_dir($ASSET)) {
    @unlink($ASSET . '/gen.py');
    @rmdir($backupDir);
    @rmdir($ASSET);
}

// ---------- 自检 ----------
$leftOrders = $ids !== '' ? (int)$db->value("SELECT COUNT(*) FROM ly_orders WHERE id IN ($ids)") : 0;
check('测试订单已全部清理', $leftOrders === 0);
check('测试商品与库存已清理',
    (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE id=?', [$p1]) === 0
    && (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id=?', [$p1]) === 0);
check('测试用户与管理员已清理',
    (int)$db->value('SELECT COUNT(*) FROM ly_users WHERE id IN (?,?)', [$user1['id'], $user2['id']]) === 0
    && (int)$db->value('SELECT COUNT(*) FROM ly_admins WHERE id=?', [$adminId]) === 0);
$leftFiles = is_dir($donateDir) ? array_values(array_diff(scandir($donateDir), ['.', '..'])) : [];
check('测试上传的收款码图片已清理（目录回到测试前）',
    $removed >= 2 && count($leftFiles) === count($preFiles));

$snapOk = true;
foreach ($snap as $k => $v) {
    if ((string)Setting::get($k, '__none__') !== $v) {
        $snapOk = false;
        echo "    - 未还原: {$k} = " . Setting::get($k, '') . "（期望 {$v}）" . PHP_EOL;
    }
}
check('系统配置已还原至测试前快照', $snapOk);
$qrBackOk = true;
foreach ($backup as $bk) {
    if (!is_file(LY_ROOT . '/' . $bk['rel'])) {
        $qrBackOk = false;
    }
}
check('现场二维码文件完整（备份恢复）', $qrBackOk);
check('捐赠开关还原一致', Setting::donateEnabled() === ($snap['donate_enabled'] === '1'));

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "捐赠支付测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
