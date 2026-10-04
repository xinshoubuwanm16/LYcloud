<?php
/**
 * 优惠券删除（含被持有时强制删除）测试（1.7.2）
 *
 * 覆盖：
 *  - 无人持有：安全删除直接成功
 *  - 有人持有（未使用）：安全删除被拒，提示含张数与口令
 *  - 强制删除：口令错误被拒 / 口令正确成功
 *  - 强制删除的级联效果：
 *      · 券包中「未使用」的实例被收回
 *      · 券包中「已使用」的实例保留（用户券包历史与订单对账需要）
 *      · 适用范围 ly_coupon_scopes 清理干净
 *      · 券模板本身删除
 *  - 数据完整性：券模板删除后券包 LEFT JOIN 查询不报错、券名回退为 NULL（前台显示「券已删除」）
 *  - 列表页展示：未使用张数 + 强制删除入口 + 口令提示
 *
 * 运行：需先启动本地 HTTP 服务（php -S 127.0.0.1:8099 -t .），
 *       LY_TEST_BASE=http://127.0.0.1:8099 php tests/coupon_delete_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Admin;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserCoupon;

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
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true];
    if ($jar !== '') { $opt[CURLOPT_COOKIEJAR] = $jar; $opt[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    curl_close($ch);
    return (string)$body;
}

function httpPost(string $url, array $data, string $jar): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return (string)$body;
}

$db = Database::instance();

/* 前置：HTTP 管理端登录 */
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$USER = 'cd_admin_' . substr(md5(uniqid('', true)), 0, 8);
$PASS = 'Cd' . random_int(100000, 999999);
$jar  = sys_get_temp_dir() . "/cd_" . uniqid() . ".jar";
$adminId = Admin::create($USER, $PASS, '券删除测试');
$loginPage = httpGet($BASE . '/index.php?r=admin/auth/login', $jar);
preg_match('/name="_token" value="([^"]+)"/', $loginPage, $m);
httpPost($BASE . '/index.php?r=admin/auth/login', ['_token' => $m[1] ?? '', 'username' => $USER, 'password' => $PASS], $jar);

$mkCoupon = function (string $tag) use ($db): int {
    $r = Coupon::create([
        'name' => '测试券' . $tag, 'code' => 'CD' . strtoupper(substr(md5(uniqid('', true)), 0, 8)),
        'type' => 1, 'value' => '5.00', 'min_amount' => '20.00', 'max_discount' => '0.00',
        'scope' => 0, 'per_user_limit' => 5, 'received_limit' => 0, 'claimable' => 0,
        'status' => 1, 'start_at' => null, 'expires_at' => null, 'remark' => 'test',
    ], [], 0);
    return (int)$r['id'];
};
$mkUser = function () {
    return User::create('cd_' . uniqid() . '@qq.com', 'Pass' . random_int(100000, 999999), '券删测试', true);
};
$adminPost = function (string $route, array $kv) use ($BASE, $jar): string {
    $page = httpGet($BASE . '/index.php?r=admin/coupon/list', $jar);
    preg_match('/name="_token" value="([^"]+)"/', $page, $m);
    $kv['_token'] = $m[1] ?? '';
    return httpPost($BASE . '/index.php?r=' . $route, $kv, $jar);
};

$cleanup = [];
$cleanUsers = [];

/* ==================== 第一节：无人持有 → 安全删除成功 ==================== */
echo "\n[1] 无人持有：安全删除\n";
$c1 = $mkCoupon('A');
$cleanup[] = $c1;
$r = Coupon::delete($c1);
$chk('无人持有时删除成功', $r['ok'] === true, $r['msg']);
$chk('券模板已删除', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$c1]) === 0);

/* ==================== 第二节：有人持有（未使用）→ 安全删除被拒 ==================== */
echo "\n[2] 有人持有：安全删除被拒\n";
$c2 = $mkCoupon('B');
$cleanup[] = $c2;
$u1 = $mkUser(); $cleanUsers[] = $u1;
$u2 = $mkUser(); $cleanUsers[] = $u2;
UserCoupon::grant($c2, [$u1, $u2]);
$u = Coupon::usage($c2);
$chk('usage() 统计未使用张数正确', $u['available'] === 2, json_encode($u));
$chk('usage() 统计持有总数正确', $u['held'] === 2, json_encode($u));

$r = Coupon::delete($c2); // 不带 force
$chk('未使用券存在时安全删除被拒', $r['ok'] === false);
$chk('拒绝文案含张数', strpos($r['msg'], '2 张') !== false, $r['msg']);
$chk('拒绝文案含确认口令', strpos($r['msg'], Coupon::FORCE_CONFIRM_WORD) !== false, $r['msg']);
$chk('拒绝后券模板仍在', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$c2]) === 1);
$chk('拒绝后券包实例未被改动', (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$c2]) === 2);

/* ==================== 第三节：口径——已使用/已失效不阻塞删除 ==================== */
echo "\n[3] 仅剩已使用的券：不阻塞删除\n";
$c3 = $mkCoupon('C');
$cleanup[] = $c3;
$u3 = $mkUser(); $cleanUsers[] = $u3;
$u4 = $mkUser(); $cleanUsers[] = $u4;
UserCoupon::grant($c3, [$u3, $u4]);
// 两张都标记为「已使用」（模拟用完）
$db->query('UPDATE ly_user_coupons SET status=1, order_id=888888 WHERE coupon_id=?', [$c3]);
$u = Coupon::usage($c3);
$chk('已使用券不计入 available', $u['available'] === 0, json_encode($u));
$chk('已使用券计入 held', $u['held'] === 2, json_encode($u));

$r = Coupon::delete($c3);
$chk('券已全部使用时可直接删除（无需 force）', $r['ok'] === true, $r['msg']);
$chk('模板已删除', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$c3]) === 0);
$chk('已使用的券包实例保留', (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$c3]) === 2);
// 券模板删除后券包查询不报错
$rows = $db->select(
    'SELECT uc.*, c.name AS coupon_name FROM ly_user_coupons uc LEFT JOIN ly_coupons c ON c.id=uc.coupon_id WHERE uc.coupon_id=?',
    [$c3]
);
$chk('模板删除后券包 LEFT JOIN 查询正常', count($rows) === 2);
$chk('券名回退为 NULL（前台显示「券已删除」）', $rows[0]['coupon_name'] === null);

/* ==================== 第四节：强制删除（直接模型层） ==================== */
echo "\n[4] 强制删除：级联效果\n";
$c4 = $mkCoupon('D');
$cleanup[] = $c4;
$u5 = $mkUser(); $cleanUsers[] = $u5;
$u6 = $mkUser(); $cleanUsers[] = $u6;
$u7 = $mkUser(); $cleanUsers[] = $u7;
UserCoupon::grant($c4, [$u5, $u6, $u7]);
// 其中一张标记已使用
$ucRows = $db->select('SELECT id FROM ly_user_coupons WHERE coupon_id=? ORDER BY id ASC', [$c4]);
$db->update('ly_user_coupons', ['status' => 1, 'order_id' => 777777], 'id=?', [(int)$ucRows[0]['id']]);
// 造一条适用范围记录
$db->insert('ly_coupon_scopes', ['coupon_id' => $c4, 'product_id' => 1]);

$u = Coupon::usage($c4);
$chk('强删前：2 张未使用 + 1 张已使用', $u['available'] === 2 && $u['held'] === 3, json_encode($u));

$r = Coupon::delete($c4, true);
$chk('强制删除成功', $r['ok'] === true, $r['msg']);
$chk('返回收回张数为 2', (int)($r['revoked'] ?? -1) === 2, (string)($r['revoked'] ?? 'n/a'));
$chk('模板已删除', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$c4]) === 0);
$chk('未使用的券实例被收回（3 → 1）', (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$c4]) === 1);
$chk('保留的正是「已使用」那张', (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=? AND status=1', [$c4]) === 1);
$chk('适用范围已清理', (int)$db->value('SELECT COUNT(*) FROM ly_coupon_scopes WHERE coupon_id=?', [$c4]) === 0);
$chk('提示含收回张数', strpos($r['msg'], '2 张') !== false, $r['msg']);

/* ==================== 第五节：不存在的券 ==================== */
echo "\n[5] 边界：券不存在\n";
$r = Coupon::delete(99999999, true);
$chk('删除不存在的券返回失败且不报错', $r['ok'] === false);
$chk('提示文案明确', strpos($r['msg'], '不存在') !== false, $r['msg']);

/* ==================== 第六节：HTTP 端到端（口令校验） ==================== */
echo "\n[6] HTTP 端到端：口令校验\n";
$c6 = $mkCoupon('E');
$cleanup[] = $c6;
$u8 = $mkUser(); $cleanUsers[] = $u8;
UserCoupon::grant($c6, [$u8]);

// 6.1 列表页展示
$listHtml = httpGet($BASE . '/index.php?r=admin/coupon/list&keyword=' . urlencode('测试券E'), $jar);
$chk('列表页显示未使用张数', strpos($listHtml, '未使用 1 张') !== false);
$chk('列表页出现强制删除入口', strpos($listHtml, '强制删除') !== false);
$chk('列表页出现确认口令输入框', strpos($listHtml, 'confirm-word') !== false);
$chk('列表页占位符含口令字样', strpos($listHtml, Coupon::FORCE_CONFIRM_WORD) !== false);

// 6.2 无 force 提交 → 拒绝
$html = $adminPost('admin/coupon/delete', ['id' => $c6]);
$chk('HTTP 无 force 删除被拒（模板仍在）', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$c6]) === 1);
$chk('拒绝 flash 含确认口令提示', strpos($html, '确认口令') !== false || strpos($html, '无法删除') !== false);

// 6.3 force + 错误口令 → 拒绝
$html = $adminPost('admin/coupon/delete', ['id' => $c6, 'force' => 1, 'confirm' => '随便写的']);
$chk('口令错误时被拒（模板仍在）', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$c6]) === 1);
$chk('口令错误提示明确', strpos($html, '口令不正确') !== false);

// 6.4 force + 正确口令 → 成功
$html = $adminPost('admin/coupon/delete', ['id' => $c6, 'force' => 1, 'confirm' => Coupon::FORCE_CONFIRM_WORD]);
$chk('正确口令强制删除成功', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$c6]) === 0);
$chk('成功提示含收回张数', strpos($html, '收回') !== false && strpos($html, '1 张') !== false);
$chk('未使用实例已收回', (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$c6]) === 0);

/* ==================== 清理 ==================== */
echo "\n[7] 清理\n";
foreach ($cleanup as $cid) {
    $db->delete('ly_user_coupons', 'coupon_id=?', [$cid]);
    $db->delete('ly_coupon_scopes', 'coupon_id=?', [$cid]);
    $db->delete('ly_coupons', 'id=?', [$cid]);
}
foreach ($cleanUsers as $uid) {
    $db->delete('ly_user_coupons', 'user_id=?', [$uid]);
    $db->delete('ly_users', 'id=?', [$uid]);
}
$db->delete('ly_admins', 'id=?', [$adminId]);
$left = 0;
foreach ($cleanup as $cid) {
    $left += (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id=?', [$cid]);
}
$chk('测试数据已清理', $left === 0);

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "优惠券删除测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
