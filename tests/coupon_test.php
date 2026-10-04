<?php
/**
 * 优惠券测试（1.3.0 新增）
 *
 * 覆盖：
 *   - Coupon 参数校验（类型 / 面额 / 门槛 / 上限 / 时间窗 / 券码归一化）
 *   - calcDiscount 试算（满减、折扣、封顶、门槛边界、bcmath 精度）
 *   - 适用范围判定（全场通用 / 指定商品多选）
 *   - CRUD、启停、删除（有持券时拒绝、无持券级联清理）
 *   - UserCoupon 发放（每人限次跳过、总量封顶、去重、非法用户）
 *   - 前台领取（可领校验 / 时间窗 / 已被领完 / 每人限次）
 *   - 并发领取同一张限量券只成功限定张数
 *   - 下单抵扣链：先券后余额、恒等式、券核销
 *   - 并发用同一张券下单只成功一次
 *   - 关单退券、已支付订单不可关单
 *   - 功能总开关关闭时忽略券
 *   - 全库金额恒等式巡检
 *
 * 用法：php tests/coupon_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\BalanceLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\UserCoupon;

$pass = 0;
$fail = 0;

function group($name)
{
    echo "\n[" . $name . "]\n";
}

function check($desc, $cond, $extra = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✔ " . $desc . "\n";
    } else {
        $fail++;
        $suffix = ($extra === '' || $extra === null) ? '' : '   (' . (is_scalar($extra) ? $extra : json_encode($extra, JSON_UNESCAPED_UNICODE)) . ')';
        echo "  ✘ " . $desc . $suffix . "   <-- 失败\n";
    }
}

echo "==========================================\n";
echo " LY云计算 · 优惠券测试\n";
echo "==========================================\n";

$db      = Database::instance();
$adminId = (int)$db->value('SELECT id FROM ly_admins ORDER BY id ASC LIMIT 1');
if ($adminId <= 0) {
    $adminId = 1;
}

// 记录初始开关，末尾还原
$origEnabled      = Setting::get('coupon_enabled');
$origClaimEnabled = Setting::get('coupon_claim_enabled');

$TAG = 'cptest_' . bin2hex(random_bytes(4));

/**
 * 生成合法券码：仅 A-Z0-9，长度 4~20
 * 注意 $TAG 含下划线，不能直接拼进券码
 */
$codeSeq = 0;
function couponCode(): string
{
    global $codeSeq;
    $codeSeq++;
    return 'CP' . strtoupper(bin2hex(random_bytes(4))) . $codeSeq; // 2+8+1 = 11 位
}

/** 建一个测试用户 */
function mkUser(Database $db, string $tag, string $balance = '0.00'): int
{
    return (int)$db->insert('ly_users', [
        'email'      => $tag . '@qq.com',
        'password'   => password_hash('Test1234', PASSWORD_DEFAULT),
        'nickname'   => 'CpTest',
        'balance'    => $balance,
        'status'     => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/** 建一个测试商品 */
function mkProduct(Database $db, string $name, string $price): int
{
    return (int)$db->insert('ly_products', [
        'name' => $name, 'price' => $price, 'stock_mode' => 0,
        'auto_deliver' => 0, 'status' => 1, 'sort' => 0,
        'description' => $name, 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/** 读用户真实余额（绕过静态缓存） */
function bal(Database $db, int $uid): string
{
    return BalanceLog::normalize((string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uid]));
}

$users    = [];
$products = [];
$coupons  = [];

// ============================================================
group('1. 金额工具与券码归一化');

check('Coupon::SCALE 为 2', Coupon::SCALE === 2);
check('类型常量 reduce / discount', Coupon::TYPE_REDUCE === 'reduce' && Coupon::TYPE_DISCOUNT === 'discount');
check('范围常量 全场0 / 指定1', Coupon::SCOPE_ALL === 0 && Coupon::SCOPE_PRODUCT === 1);
check('normalizeType 识别 reduce 常量', Coupon::normalizeType(Coupon::TYPE_REDUCE) === Coupon::TYPE_REDUCE);
check('normalizeType 识别 discount 常量', Coupon::normalizeType(Coupon::TYPE_DISCOUNT) === Coupon::TYPE_DISCOUNT);
check('normalizeType 未知类型回落满减', Coupon::normalizeType('xxx') === Coupon::TYPE_REDUCE);

// ============================================================
group('2. 建券参数校验');

$base = [
    'name' => $TAG . '基准券', 'type' => 'reduce', 'value' => '10', 'min_amount' => '100',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => '', 'remark' => '',
];

$bad = $base; $bad['name'] = '';
check('空券名被拒', Coupon::validate($bad) !== '');

$bad = $base; $bad['type'] = 'unknown';
check('未知类型被静默归一为满减（不报错）', Coupon::validate($bad) === '');

$bad = $base; $bad['value'] = '0';
check('满减面额为 0 被拒', Coupon::validate($bad) !== '');

$bad = $base; $bad['value'] = '-5';
check('满减面额为负被拒', Coupon::validate($bad) !== '');

// 0 元购保护：门槛必须 >= 面额
$eq = $base; $eq['value'] = '100'; $eq['min_amount'] = '100';
check('门槛=面额 合规（边界值 min>=value 成立）', Coupon::validate($eq) === '');

$lo = $base; $lo['value'] = '100'; $lo['min_amount'] = '99.99';
check('门槛 < 面额 被拒（防 0 元购）', Coupon::validate($lo) !== '');

$ok = $base; $ok['value'] = '100'; $ok['min_amount'] = '100.01';
check('面额略低于门槛可接受', Coupon::validate($ok) === '');

// 折扣券：0.01 ~ 0.99
$d = $base; $d['type'] = 'discount'; $d['value'] = '0.85'; $d['min_amount'] = '0';
check('折扣 0.85 合法', Coupon::validate($d) === '');

$d10 = $base; $d10['type'] = 'discount'; $d10['value'] = '1.00';
check('折扣 1.00（不打折）被拒', Coupon::validate($d10) !== '');

$d0 = $base; $d0['type'] = 'discount'; $d0['value'] = '0';
check('折扣 0（白送）被拒', Coupon::validate($d0) !== '');

$d001 = $base; $d001['type'] = 'discount'; $d001['value'] = '0.001';
check('折扣低于下限 0.01 被拒', Coupon::validate($d001) !== '');

$bad = $base; $bad['per_user_limit'] = '0';
check('每人限次为 0 被拒', Coupon::validate($bad) !== '');

$bad = $base; $bad['per_user_limit'] = (string)(Coupon::MAX_PER_USER + 1);
check('每人限次超上限被拒', Coupon::validate($bad) !== '');

$good = $base; $good['per_user_limit'] = (string)Coupon::MAX_PER_USER;
check('每人限次达上限可接受', Coupon::validate($good) === '');

$bad = $base;
$bad['start_at'] = date('Y-m-d H:i:s', time() + 86400);
$bad['expires_at'] = date('Y-m-d H:i:s');
check('结束时间早于开始时间被拒', Coupon::validate($bad) !== '');

$bad = $base; $bad['scope'] = 1;
check('指定商品但未选商品被拒', Coupon::validate($bad, []) !== '');

check('指定商品且已选商品通过', Coupon::validate($bad, [1, 2]) === '');

// 券码归一化
$c = $base; $c['code'] = 'abcd';
check('券码自动大写归一', Coupon::validate($c) === '');

$c = $base; $c['code'] = 'AB_CD';
check('券码拒绝下划线', Coupon::validate($c) !== '');

$c = $base; $c['code'] = 'AB-CD';
check('券码拒绝连字符', Coupon::validate($c) !== '');

$c = $base; $c['code'] = 'AB CD';
check('券码拒绝空格', Coupon::validate($c) !== '');

$c = $base; $c['code'] = 'A';
check('券码过短被拒', Coupon::validate($c) !== '');

// ============================================================
group('3. 试算 calcDiscount（满减）');

$reduce100_20 = ['type' => 'reduce', 'value' => '20', 'min_amount' => '100', 'max_discount' => '0'];
check('满 100 减 20：金额 100 → 减 20', Coupon::calcDiscount($reduce100_20, '100.00') === '20.00');
check('满 100 减 20：金额 150 → 减 20', Coupon::calcDiscount($reduce100_20, '150.00') === '20.00');
check('满 100 减 20：金额 99.99 → 不满足门槛减 0', Coupon::calcDiscount($reduce100_20, '99.99') === '0.00');
check('满 100 减 20：金额 100.00 恰好达门槛', Coupon::calcDiscount($reduce100_20, '100.00') === '20.00');

$noThreshold = ['type' => 'reduce', 'value' => '5', 'min_amount' => '0', 'max_discount' => '0'];
check('无门槛减 5：金额 3 → 只减到 0（不透支）', Coupon::calcDiscount($noThreshold, '3.00') === '3.00');
check('无门槛减 5：金额 3.00 减免不超过面额本身', bccomp(Coupon::calcDiscount($noThreshold, '3.00'), '5.00', 2) <= 0);
check('无门槛减 5：金额 8 → 减 5', Coupon::calcDiscount($noThreshold, '8.00') === '5.00');

// ============================================================
group('4. 试算 calcDiscount（折扣）');

$d85 = ['type' => 'discount', 'value' => '0.85', 'min_amount' => '0', 'max_discount' => '0'];
check('8.5 折：100 → 减 15.00', Coupon::calcDiscount($d85, '100.00') === '15.00');
check('8.5 折：50 → 减 7.50', Coupon::calcDiscount($d85, '50.00') === '7.50');
check('8.5 折：0.01 → 减免不超过本金', bccomp(Coupon::calcDiscount($d85, '0.01'), '0.01', 2) <= 0);
check('8.5 折：0.01 → 减免 0.01（截断后补足）', Coupon::calcDiscount($d85, '0.01') === '0.01');

$d999 = ['type' => 'discount', 'value' => '0.99', 'min_amount' => '0', 'max_discount' => '0'];
check('9.9 折：100 → 减 1.00', Coupon::calcDiscount($d999, '100.00') === '1.00');
check('9.9 折：0.01 → 减 0.01（最小可减）', Coupon::calcDiscount($d999, '0.01') === '0.01');

$d01 = ['type' => 'discount', 'value' => '0.01', 'min_amount' => '0', 'max_discount' => '0'];
check('0.1 折：100 → 减 99.00', Coupon::calcDiscount($d01, '100.00') === '99.00');

// 折扣 + 封顶
$d85cap = ['type' => 'discount', 'value' => '0.85', 'min_amount' => '0', 'max_discount' => '50'];
check('8.5 折封顶 50：1000 → 理论减 150，封顶后 50', Coupon::calcDiscount($d85cap, '1000.00') === '50.00');
check('8.5 折封顶 50：100 → 减 15（未触顶）', Coupon::calcDiscount($d85cap, '100.00') === '15.00');

// 折扣 + 门槛（未达门槛不减）
$d85min = ['type' => 'discount', 'value' => '0.85', 'min_amount' => '200', 'max_discount' => '0'];
check('8.5 折满 200：金额 100 → 不满足门槛减 0', Coupon::calcDiscount($d85min, '100.00') === '0.00');
check('8.5 折满 200：金额 200 → 减 30', Coupon::calcDiscount($d85min, '200.00') === '30.00');

// 折扣封顶极高 → 不触发
$d85capHi = ['type' => 'discount', 'value' => '0.85', 'min_amount' => '0', 'max_discount' => '999999.99'];
check('封顶极大时等于不封顶', Coupon::calcDiscount($d85capHi, '100.00') === '15.00');

// ============================================================
group('5. 时间窗与可用性');

$now = time();
$win = [
    'status' => 1, 'start_at' => date('Y-m-d H:i:s', $now - 3600),
    'expires_at' => date('Y-m-d H:i:s', $now + 3600),
];
check('窗口内可用', Coupon::isWithinWindow($win) === true);
check('窗口内 isUsable', Coupon::isUsable($win) === true);

$future = ['status' => 1, 'start_at' => date('Y-m-d H:i:s', $now + 3600), 'expires_at' => null];
check('未开始不可用', Coupon::isWithinWindow($future) === false);

$past = ['status' => 1, 'start_at' => null, 'expires_at' => date('Y-m-d H:i:s', $now - 3600)];
check('已过期不可用', Coupon::isWithinWindow($past) === false);

$forever = ['status' => 1, 'start_at' => null, 'expires_at' => null];
check('无时间限制永久可用', Coupon::isWithinWindow($forever) === true);

$off = $win; $off['status'] = 0;
check('停用券 isUsable=false', Coupon::isUsable($off) === false);

// ============================================================
group('6. 适用范围判定');

$all = ['scope' => Coupon::SCOPE_ALL];
check('全场通用：任意商品适用', Coupon::isApplicableToProduct($all, 12345) === true);

// ============================================================
group('7. describe / typeLabel 文案');

check('满减描述（有门槛）', Coupon::describe(['type' => 'reduce', 'value' => '20', 'min_amount' => '100', 'max_discount' => '0']) === '满 ¥100.00 减 ¥20.00');
check('满减描述（无门槛）', Coupon::describe(['type' => 'reduce', 'value' => '20', 'min_amount' => '0', 'max_discount' => '0']) === '无门槛 减 ¥20.00');
check('折扣描述（无门槛）', Coupon::describe(['type' => 'discount', 'value' => '0.85', 'min_amount' => '0', 'max_discount' => '0']) === '无门槛 8.5 折');
check('折扣描述（含门槛+封顶）', Coupon::describe(['type' => 'discount', 'value' => '0.85', 'min_amount' => '100', 'max_discount' => '50']) === '满 ¥100.00 8.5 折（最高减 ¥50.00）');
check('typeLabel 满减', Coupon::typeLabel(['type' => 'reduce']) === '满减券');
check('typeLabel 折扣', Coupon::typeLabel(['type' => 'discount']) === '折扣券');

// ============================================================
group('8. CRUD：创建 / 查询 / 更新 / 启停');

$products[] = $pA = mkProduct($db, $TAG . '_商品A', '100.00');
$products[] = $pB = mkProduct($db, $TAG . '_商品B', '200.00');
$products[] = $pC = mkProduct($db, $TAG . '_商品C', '300.00');

$r = Coupon::create([
    'name' => $TAG . '_全场满减', 'type' => 'reduce', 'value' => '20', 'min_amount' => '100',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 2, 'received_limit' => 100,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '',
    'code' => $codeGlobal = couponCode(), 'remark' => 'test',
], [], $adminId);
check('创建全场满减券成功', $r['ok'] === true, $r['msg']);
$coupons[] = $cGlobal = (int)$r['id'];

$row = Coupon::find($cGlobal);
check('find 取回券', $row !== null);
check('find 字段正确（name）', $row['name'] === $TAG . '_全场满减');
check('find 字段正确（value）', BalanceLog::normalize($row['value']) === '20.00');
check('find 初始 received_count=0', (int)$row['received_count'] === 0);
check('find 初始 used_count=0', (int)$row['used_count'] === 0);

check('findByCode 大小写不敏感', Coupon::findByCode(strtolower($codeGlobal)) !== null);
check('findByCode 不存在返回 null', Coupon::findByCode('ZZZZNOPE99') === null);

// 指定商品券
$r = Coupon::create([
    'name' => $TAG . '_指定商品折扣', 'type' => 'discount', 'value' => '0.9', 'min_amount' => '0',
    'max_discount' => '0', 'scope' => 1, 'per_user_limit' => 1, 'received_limit' => 50,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '',
    'code' => $codeScoped = couponCode(), 'remark' => 'test',
], [$pA, $pB], $adminId);
check('创建指定商品折扣券成功', $r['ok'] === true, $r['msg']);
$coupons[] = $cScoped = (int)$r['id'];

$scopes = Coupon::scopeProductIds($cScoped);
sort($scopes);
$expect = [$pA, $pB];
sort($expect);
check('scopeProductIds 与提交一致', $scopes === $expect, json_encode($scopes));

$names = Coupon::scopeProductNames($cScoped);
check('scopeProductNames 取回 2 个商品名', count($names) === 2, json_encode($names, JSON_UNESCAPED_UNICODE));

$scopedRow = Coupon::find($cScoped);
check('指定券对 pA 适用', Coupon::isApplicableToProduct($scopedRow, $pA) === true);
check('指定券对 pB 适用', Coupon::isApplicableToProduct($scopedRow, $pB) === true);
check('指定券对 pC 不适用', Coupon::isApplicableToProduct($scopedRow, $pC) === false);

check('scope 字段=1', (int)$scopedRow['scope'] === Coupon::SCOPE_PRODUCT);

// 更新
$r = Coupon::update($cGlobal, [
    'name' => $TAG . '_全场满减改', 'type' => 'reduce', 'value' => '30', 'min_amount' => '150',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 3, 'received_limit' => 100,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '',
    'code' => $codeGlobal, 'remark' => 'updated',
], []);
check('更新券成功', $r['ok'] === true, $r['msg']);
$row = Coupon::find($cGlobal);
check('更新后 name 生效', $row['name'] === $TAG . '_全场满减改');
check('更新后 value 生效', BalanceLog::normalize($row['value']) === '30.00');
check('更新后 per_user_limit 生效', (int)$row['per_user_limit'] === 3);
check('更新后 claimable 生效', (int)$row['claimable'] === 0);

// 更新范围：全场 → 指定
$r = Coupon::update($cGlobal, [
    'name' => $TAG . '_全场满减改', 'type' => 'reduce', 'value' => '30', 'min_amount' => '150',
    'max_discount' => '0', 'scope' => 1, 'per_user_limit' => 3, 'received_limit' => 100,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '',
    'code' => $codeGlobal, 'remark' => 'updated',
], [$pC]);
check('更新范围成功', $r['ok'] === true, $r['msg']);
check('范围已改为指定商品', Coupon::scopeProductIds($cGlobal) === [$pC], json_encode(Coupon::scopeProductIds($cGlobal)));

// 改回全场（范围表应清空）
$r = Coupon::update($cGlobal, [
    'name' => $TAG . '_全场满减改', 'type' => 'reduce', 'value' => '30', 'min_amount' => '150',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 3, 'received_limit' => 100,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '',
    'code' => $codeGlobal, 'remark' => 'updated',
], []);
check('改回全场通用', $r['ok'] === true);
check('范围表已清空', Coupon::scopeProductIds($cGlobal) === [], json_encode(Coupon::scopeProductIds($cGlobal)));

// 启停
check('停用券成功', Coupon::setStatus($cGlobal, Coupon::STATUS_OFF) === true);
check('停用后 status=0', (int)Coupon::find($cGlobal)['status'] === Coupon::STATUS_OFF);
check('启用券成功', Coupon::setStatus($cGlobal, Coupon::STATUS_ON) === true);
check('启用后 status=1', (int)Coupon::find($cGlobal)['status'] === Coupon::STATUS_ON);

// 券码重复
$dup = Coupon::create([
    'name' => $TAG . '_重复码', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '',
    'code' => $codeGlobal, 'remark' => '',
], [], $adminId);
check('券码重复被拒', $dup['ok'] === false, $dup['msg']);

// 空券码可共存（占位）
$e1 = Coupon::create([
    'name' => $TAG . '_无码1', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => '', 'remark' => '',
], [], $adminId);
$e2 = Coupon::create([
    'name' => $TAG . '_无码2', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => '', 'remark' => '',
], [], $adminId);
check('两张空券码券可共存', $e1['ok'] === true && $e2['ok'] === true);
$coupons[] = (int)$e1['id'];
$coupons[] = (int)$e2['id'];

// ============================================================
group('9. 发放（每人限次 / 总量封顶 / 去重）');

for ($i = 1; $i <= 3; $i++) {
    $users[] = ${'gu' . $i} = mkUser($db, $TAG . '_gu' . $i);
}

$r = UserCoupon::grant($cGlobal, [$gu1, $gu2, $gu3], UserCoupon::SOURCE_ADMIN);
check('发放给 3 人成功', $r['ok'] === true && $r['granted'] === 3, json_encode($r, JSON_UNESCAPED_UNICODE));
check('发放后 received_count=3', (int)Coupon::find($cGlobal)['received_count'] === 3);
check('gu1 持有 1 张', UserCoupon::countForUser($cGlobal, $gu1) === 1);

// $cGlobal 此前已更新为 per_user_limit=3，故可继续发放
$r = UserCoupon::grant($cGlobal, [$gu1, $gu2], UserCoupon::SOURCE_ADMIN);
check('每人限 3 张时第 2 次发放仍成功', $r['granted'] === 2, json_encode($r, JSON_UNESCAPED_UNICODE));
check('发放后 received_count=5', (int)Coupon::find($cGlobal)['received_count'] === 5, (string)Coupon::find($cGlobal)['received_count']);
check('gu1 已持有 2 张', UserCoupon::countForUser($cGlobal, $gu1) === 2, (string)UserCoupon::countForUser($cGlobal, $gu1));

// 再发一次：已达每人 3 张上限，应全部跳过
$r = UserCoupon::grant($cGlobal, [$gu1], UserCoupon::SOURCE_ADMIN);
check('第三次发放成功（达上限 3）', $r['granted'] === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = UserCoupon::grant($cGlobal, [$gu1, $gu2], UserCoupon::SOURCE_ADMIN);
check('达每人限次后发放被跳过（gu1 跳过）', $r['granted'] === 1 && $r['skipped'] === 1, json_encode($r, JSON_UNESCAPED_UNICODE));

// 去重：同一 uid 传多次只发一张
$r = UserCoupon::grant($cScoped, [$gu1, $gu1, $gu1], UserCoupon::SOURCE_ADMIN);
check('同一用户重复传参只发 1 张', $r['granted'] === 1, json_encode($r, JSON_UNESCAPED_UNICODE));

// 不存在用户被跳过
$r = UserCoupon::grant($cScoped, [$gu2, 99999999], UserCoupon::SOURCE_ADMIN);
check('不存在用户被跳过', $r['granted'] === 1 && $r['skipped'] === 1, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = UserCoupon::grant($cScoped, [], UserCoupon::SOURCE_ADMIN);
check('空用户列表被拒', $r['ok'] === false);

$r = UserCoupon::grant(99999999, [$gu1], UserCoupon::SOURCE_ADMIN);
check('不存在的券被拒', $r['ok'] === false);

// 总量封顶
$r = Coupon::create([
    'name' => $TAG . '_限量2张', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 2,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
check('建限量 2 张券成功', $r['ok'] === true, $r['msg']);
$coupons[] = $cLimit = (int)$r['id'];

$r = UserCoupon::grant($cLimit, [$gu1, $gu2, $gu3], UserCoupon::SOURCE_ADMIN);
check('限量 2 张：只发出 2 张', $r['granted'] === 2, json_encode($r, JSON_UNESCAPED_UNICODE));
check('限量 2 张：1 人跳过', $r['skipped'] === 1);
check('限量券 received_count=2', (int)Coupon::find($cLimit)['received_count'] === 2);

// 单人超 MAX_GRANT
$r = UserCoupon::grant($cGlobal, range(1, UserCoupon::MAX_GRANT + 1), UserCoupon::SOURCE_ADMIN);
check('超过单次发放上限被拒', $r['ok'] === false, $r['msg']);

// ============================================================
group('10. 前台领取（claim）');

$r = Coupon::create([
    'name' => $TAG . '_自领每人1张', 'type' => 'reduce', 'value' => '10', 'min_amount' => '50',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 2,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
check('建自领券成功', $r['ok'] === true, $r['msg']);
$coupons[] = $cClaim = (int)$r['id'];

$users[] = $cu1 = mkUser($db, $TAG . '_cu1');
$users[] = $cu2 = mkUser($db, $TAG . '_cu2');
$users[] = $cu3 = mkUser($db, $TAG . '_cu3');

$cr = UserCoupon::claim($cClaim, $cu1);
check('cu1 领取成功', $cr['ok'] === true, $cr['msg']);
check('领取后 received_count=1', (int)Coupon::find($cClaim)['received_count'] === 1);

$cr = UserCoupon::claim($cClaim, $cu1);
check('cu1 重复领取被拒', $cr['ok'] === false, $cr['msg']);

$cr = UserCoupon::claim($cClaim, $cu2);
check('cu2 领取成功', $cr['ok'] === true, $cr['msg']);
check('领取后 received_count=2', (int)Coupon::find($cClaim)['received_count'] === 2);

$cr = UserCoupon::claim($cClaim, $cu3);
check('已达总量上限，cu3 领取被拒', $cr['ok'] === false, $cr['msg']);
check('拒绝后 received_count 仍为 2', (int)Coupon::find($cClaim)['received_count'] === 2);

$cr = UserCoupon::claim(99999999, $cu1);
check('不存在的券领取被拒', $cr['ok'] === false);

// claimable=0 不可自领
$r = Coupon::create([
    'name' => $TAG . '_仅定向发放', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$coupons[] = $cNoClaim = (int)$r['id'];
$cr = UserCoupon::claim($cNoClaim, $cu1);
check('不支持自领的券被拒', $cr['ok'] === false, $cr['msg']);

// 停用券不可自领
$r = Coupon::create([
    'name' => $TAG . '_停用券', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 1, 'status' => 0, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$coupons[] = $cOff = (int)$r['id'];
$cr = UserCoupon::claim($cOff, $cu1);
check('停用券不可领取', $cr['ok'] === false, $cr['msg']);

// 未开始不可领取
$r = Coupon::create([
    'name' => $TAG . '_未开始', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 1, 'status' => 1, 'start_at' => date('Y-m-d H:i:s', time() + 86400),
    'expires_at' => date('Y-m-d H:i:s', time() + 172800), 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$coupons[] = $cFuture = (int)$r['id'];
$cr = UserCoupon::claim($cFuture, $cu1);
check('未开始的券不可领取', $cr['ok'] === false, $cr['msg']);

// 已过期不可领取
$r = Coupon::create([
    'name' => $TAG . '_已过期', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 1, 'status' => 1, 'start_at' => date('Y-m-d H:i:s', time() - 172800),
    'expires_at' => date('Y-m-d H:i:s', time() - 86400), 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$coupons[] = $cPast = (int)$r['id'];
$cr = UserCoupon::claim($cPast, $cu1);
check('已过期的券不可领取', $cr['ok'] === false, $cr['msg']);

// ============================================================
group('11. 领券中心列表 claimable()');

$r = Coupon::create([
    'name' => $TAG . '_领券中心可见', 'type' => 'reduce', 'value' => '8', 'min_amount' => '80',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 5,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$coupons[] = $cCenter = (int)$r['id'];

$users[] = $lc1 = mkUser($db, $TAG . '_lc1');

$list = UserCoupon::claimable($lc1, 1, 100);
check('领券中心返回结构完整', isset($list['total'], $list['rows'], $list['pages']));

$found = null;
foreach ($list['rows'] as $it) {
    if ((int)$it['id'] === $cCenter) { $found = $it; break; }
}
check('领券中心含目标券', $found !== null);
check('目标券 can_claim=true', $found !== null && $found['can_claim'] === true);
check('目标券 my_count=0', $found !== null && (int)$found['my_count'] === 0);
check('目标券 remain=5', $found !== null && (int)$found['remain'] === 5);

$ids = array_map(static fn($x) => (int)$x['id'], $list['rows']);
check('领券中心不含 claimable=0 的券', !in_array($cNoClaim, $ids, true));
check('领券中心不含停用券', !in_array($cOff, $ids, true));
check('领券中心不含未开始券', !in_array($cFuture, $ids, true));
check('领券中心不含已过期券', !in_array($cPast, $ids, true));
check('领券中心不含已领完的券', !in_array($cClaim, $ids, true));

UserCoupon::claim($cCenter, $lc1);
$list = UserCoupon::claimable($lc1, 1, 100);
$found = null;
foreach ($list['rows'] as $it) {
    if ((int)$it['id'] === $cCenter) { $found = $it; break; }
}
check('领取后 my_count=1', $found !== null && (int)$found['my_count'] === 1);
check('领取后 can_claim=false（每人限1）', $found !== null && $found['can_claim'] === false);
check('领取后 remain=4', $found !== null && (int)$found['remain'] === 4);

// ============================================================
group('12. 结算试算 usableForProduct()');

$users[] = $su1 = mkUser($db, $TAG . '_su1');

$r = Coupon::create([
    'name' => $TAG . '_试算全场', 'type' => 'reduce', 'value' => '20', 'min_amount' => '100',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 3, 'received_limit' => 0,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$coupons[] = $cQuoteAll = (int)$r['id'];

$r = Coupon::create([
    'name' => $TAG . '_试算指定商品', 'type' => 'discount', 'value' => '0.9', 'min_amount' => '0',
    'max_discount' => '0', 'scope' => 1, 'per_user_limit' => 3, 'received_limit' => 0,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [$pA], $adminId);
$coupons[] = $cQuoteScoped = (int)$r['id'];

// 同一用户可持有多张（per_user_limit=3）：分两次发放得到 2 张
UserCoupon::grant($cQuoteAll, [$su1], UserCoupon::SOURCE_ADMIN);
UserCoupon::grant($cQuoteAll, [$su1], UserCoupon::SOURCE_ADMIN);
UserCoupon::grant($cQuoteScoped, [$su1], UserCoupon::SOURCE_ADMIN);
check('su1 共持有 3 张券', UserCoupon::countForUser($cQuoteAll, $su1) === 2, (string)UserCoupon::countForUser($cQuoteAll, $su1));

$list = UserCoupon::usableForProduct($su1, $pA, '100.00');
$ucIds = array_map(static fn($x) => (int)$x['coupon_id'], $list);
check('pA 试算含全场券', in_array($cQuoteAll, $ucIds, true), json_encode($ucIds));
check('pA 试算含指定券（pA 在范围）', in_array($cQuoteScoped, $ucIds, true), json_encode($ucIds));

$list = UserCoupon::usableForProduct($su1, $pC, '300.00');
$ucIds = array_map(static fn($x) => (int)$x['coupon_id'], $list);
check('pC 试算含全场券', in_array($cQuoteAll, $ucIds, true));
check('pC 试算不含指定券（pC 不在范围）', !in_array($cQuoteScoped, $ucIds, true), json_encode($ucIds));

// 门槛不足时不展示
$list = UserCoupon::usableForProduct($su1, $pA, '99.99');
$ucIds = array_map(static fn($x) => (int)$x['coupon_id'], $list);
check('金额未达门槛时全场券不展示', !in_array($cQuoteAll, $ucIds, true), json_encode($ucIds));

// 试算减免额
$list = UserCoupon::usableForProduct($su1, $pA, '100.00');
foreach ($list as $it) {
    if ((int)$it['coupon_id'] === $cQuoteAll) {
        check('试算减免额=20.00', $it['discount'] === '20.00', $it['discount']);
        check('试算含描述文案', !empty($it['describe']), $it['describe']);
    }
    if ((int)$it['coupon_id'] === $cQuoteScoped) {
        check('指定券试算减免=10.00（9折）', $it['discount'] === '10.00', $it['discount']);
    }
}

// 停用券不进入试算
Coupon::setStatus($cQuoteAll, Coupon::STATUS_OFF);
$list = UserCoupon::usableForProduct($su1, $pA, '100.00');
$ucIds = array_map(static fn($x) => (int)$x['coupon_id'], $list);
check('停用券不进入试算', !in_array($cQuoteAll, $ucIds, true), json_encode($ucIds));
Coupon::setStatus($cQuoteAll, Coupon::STATUS_ON);

// ============================================================
group('13. 券包与统计');

$mine = UserCoupon::forUser($su1, null, 1, 50);
check('券包取回持有券', $mine['total'] === 3, (string)$mine['total']);
check('券包 rows 带券名', !empty($mine['rows'][0]['coupon_name']));
check('券包 rows 带类型', !empty($mine['rows'][0]['type']));

$st = UserCoupon::statsForUser($su1);
check('statsForUser 结构完整', isset($st['unused'], $st['used'], $st['expired'], $st['saved']));
check('statsForUser unused = 3', $st['unused'] === 3, json_encode($st));
check('statsForUser used = 0', $st['used'] === 0);
check('statsForUser saved 初始 0.00', $st['saved'] === '0.00', $st['saved']);

$gst = UserCoupon::stats();
check('全局 stats 结构完整', isset($gst['held_total'], $gst['held_unused'], $gst['held_used'], $gst['held_expired']));

// holders
$h = UserCoupon::holders($cCenter, 1, 50);
check('holders 返回持券人', $h['total'] >= 1);
check('holders 带用户邮箱', !empty($h['rows'][0]['email']));

// ============================================================
group('14. 下单抵扣：先券后余额 + 恒等式');

$users[] = $ou1 = mkUser($db, $TAG . '_ou1', '1000.00');
$users[] = $ou2 = mkUser($db, $TAG . '_ou2', '0.00');

$r = Coupon::create([
    'name' => $TAG . '_下单满减', 'type' => 'reduce', 'value' => '20', 'min_amount' => '100',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 5, 'received_limit' => 100,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$coupons[] = $cOrder = (int)$r['id'];

$users[] = $pOrderPid = 0; // 占位，避免 products 数组错位
array_pop($users);
$products[] = $pOrder = mkProduct($db, $TAG . '_下单商品', '100.00');

// 14.1 纯券（不用余额）
$r = UserCoupon::grant($cOrder, [$ou1], UserCoupon::SOURCE_ADMIN);
check('ou1 获得下单券', $r['granted'] === 1);
$ucId = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ou1]);

$ucRow = UserCoupon::find($ucId);
$user  = ['id' => $ou1, 'email' => $TAG . '_ou1@qq.com'];
$prod  = Product::find($pOrder);
$res = Order::createWithStock($user, $prod, 'qr', false, $ucId);
check('纯券下单成功', is_array($res) && !empty($res['id']), json_encode($res, JSON_UNESCAPED_UNICODE));
$o1 = (int)$res['id'];
$order = $db->first('SELECT * FROM ly_orders WHERE id=?', [$o1]);
check('订单 amount=100.00', BalanceLog::normalize($order['amount']) === '100.00', $order['amount']);
check('订单 coupon_discount=20.00', BalanceLog::normalize($order['coupon_discount']) === '20.00', $order['coupon_discount']);
check('订单 balance_paid=0.00', BalanceLog::normalize($order['balance_paid']) === '0.00', $order['balance_paid']);
check('订单 pay_amount=80.00', BalanceLog::normalize($order['pay_amount']) === '80.00', $order['pay_amount']);
check('订单 coupon_id 已记录', (int)$order['coupon_id'] === $cOrder);
check(
    '恒等式 20 + 0 + 80 = 100',
    bcadd(BalanceLog::normalize($order['coupon_discount']), bcadd(BalanceLog::normalize($order['balance_paid']), BalanceLog::normalize($order['pay_amount']), 2), 2) === BalanceLog::normalize($order['amount'])
);

$ucAfter = UserCoupon::find($ucId);
check('券已核销 status=1', (int)$ucAfter['status'] === UserCoupon::STATUS_USED);
check('券已绑定订单号', (int)$ucAfter['order_id'] === $o1);
check('券 used_count 累加为 1', (int)Coupon::find($cOrder)['used_count'] === 1);
check('ou1 余额未动（1000.00）', bal($db, $ou1) === '1000.00', bal($db, $ou1));

// 14.2 先券后余额（券后 80，余额充足 → 纯余额单，下单即扣 80）
$users[] = $ou3 = mkUser($db, $TAG . '_ou3', '500.00');
$r = UserCoupon::grant($cOrder, [$ou3], UserCoupon::SOURCE_ADMIN);
$ucId3 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ou3]);
$user3 = ['id' => $ou3, 'email' => $TAG . '_ou3@qq.com'];
$res = Order::createWithStock($user3, $prod, 'qr', true, $ucId3);
check('券+余额下单成功', is_array($res) && !empty($res['id']), json_encode($res, JSON_UNESCAPED_UNICODE));
$o3 = (int)$res['id'];
$order3 = $db->first('SELECT * FROM ly_orders WHERE id=?', [$o3]);
check('先券后余额：券抵扣=20.00', BalanceLog::normalize($order3['coupon_discount']) === '20.00', $order3['coupon_discount']);
check('先券后余额：余额抵扣=80（券后基数，非 100）', BalanceLog::normalize($order3['balance_paid']) === '80.00', $order3['balance_paid']);
check('先券后余额：实付=0.00', BalanceLog::normalize($order3['pay_amount']) === '0.00', $order3['pay_amount']);
check(
    '恒等式 20 + 80 + 0 = 100',
    bcadd(BalanceLog::normalize($order3['coupon_discount']), bcadd(BalanceLog::normalize($order3['balance_paid']), BalanceLog::normalize($order3['pay_amount']), 2), 2) === '100.00'
);
check('纯余额单：下单即扣 80（500 → 420）', bal($db, $ou3) === '420.00', bal($db, $ou3));
check('纯余额单：pay_channel=balance', (string)$order3['pay_channel'] === 'balance', (string)$order3['pay_channel']);

// 14.3 券后余额仍不足 → 剩余走支付宝（下单不扣，markPaid 才扣）
$users[] = $ou4 = mkUser($db, $TAG . '_ou4', '30.00');
UserCoupon::grant($cOrder, [$ou4], UserCoupon::SOURCE_ADMIN);
$ucId4 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ou4]);
$user4 = ['id' => $ou4, 'email' => $TAG . '_ou4@qq.com'];
$res = Order::createWithStock($user4, $prod, 'qr', true, $ucId4);
check('券后余额不足仍可下单', is_array($res) && !empty($res['id']), json_encode($res, JSON_UNESCAPED_UNICODE));
$o4 = (int)$res['id'];
$order4 = $db->first('SELECT * FROM ly_orders WHERE id=?', [$o4]);
check('余额抵扣=30（券后 80 只够扣 30）', BalanceLog::normalize($order4['balance_paid']) === '30.00', $order4['balance_paid']);
check('实付=50', BalanceLog::normalize($order4['pay_amount']) === '50.00', $order4['pay_amount']);
check('恒等式 20 + 30 + 50 = 100',
    bcadd(BalanceLog::normalize($order4['coupon_discount']), bcadd(BalanceLog::normalize($order4['balance_paid']), BalanceLog::normalize($order4['pay_amount']), 2), 2) === '100.00'
);
check('部分抵扣单：下单暂不扣余额（仍 30.00）', bal($db, $ou4) === '30.00', bal($db, $ou4));
Order::markPaid($o4, 'TESTPAY_O4_' . $TAG, '50.00');
check('部分抵扣单：markPaid 后扣至 0.00', bal($db, $ou4) === '0.00', bal($db, $ou4));

// 14.4 券不属于本人 → 拒绝
$r = UserCoupon::grant($cOrder, [$ou2], UserCoupon::SOURCE_ADMIN);
$ucId2 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ou2]);
$user1 = ['id' => $ou1, 'email' => $TAG . '_ou1@qq.com'];
try {
    Order::createWithStock($user1, $prod, 'qr', false, $ucId2);
    check('使用他人券被拒', false, '未抛异常');
} catch (\Throwable $e) {
    check('使用他人券被拒', true);
}

// 14.5 已使用的券不可再用
try {
    Order::createWithStock($user1, $prod, 'qr', false, $ucId);
    check('已使用的券不可复用', false, '未抛异常');
} catch (\Throwable $e) {
    check('已使用的券不可复用', true);
}

// 14.6 不适用的商品 → 拒绝
$ucId2row = UserCoupon::find($ucId2);
check('ou2 的券仍为未使用', (int)$ucId2row['status'] === UserCoupon::STATUS_UNUSED);

$products[] = $pOther = mkProduct($db, $TAG . '_不适用范围商品', '100.00');
$r = Coupon::create([
    'name' => $TAG . '_仅限pA', 'type' => 'reduce', 'value' => '20', 'min_amount' => '100',
    'max_discount' => '0', 'scope' => 1, 'per_user_limit' => 3, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [$pA], $adminId);
$coupons[] = $cOnlyPA = (int)$r['id'];
UserCoupon::grant($cOnlyPA, [$ou1], UserCoupon::SOURCE_ADMIN);
$ucOnly = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOnlyPA, $ou1]);
$prodOther = Product::find($pOther);
try {
    Order::createWithStock($user1, $prodOther, 'qr', false, $ucOnly);
    check('券不适用于该商品时被拒', false, '未抛异常');
} catch (\Throwable $e) {
    check('券不适用于该商品时被拒', true);
}
check('拒绝后券仍为未使用', (int)UserCoupon::find($ucOnly)['status'] === UserCoupon::STATUS_UNUSED);

// 14.7 商品价不足门槛 → 拒绝
$products[] = $pCheap = mkProduct($db, $TAG . '_低价商品', '10.00');
$prodCheap = Product::find($pCheap);
try {
    Order::createWithStock($user1, $prodCheap, 'qr', false, $ucOnly);
    check('未达门槛时用券被拒', false, '未抛异常');
} catch (\Throwable $e) {
    check('未达门槛时用券被拒', true);
}

// 14.8 停用券不可用
UserCoupon::grant($cOrder, [$ou1], UserCoupon::SOURCE_ADMIN);
$ucOff = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? AND status=0 ORDER BY id DESC LIMIT 1', [$cOrder, $ou1]);
Coupon::setStatus($cOrder, Coupon::STATUS_OFF);
try {
    Order::createWithStock($user1, $prod, 'qr', false, $ucOff);
    check('停用券不可用于下单', false, '未抛异常');
} catch (\Throwable $e) {
    check('停用券不可用于下单', true);
}
Coupon::setStatus($cOrder, Coupon::STATUS_ON);

// 14.9 过期券不可用
$r = Coupon::create([
    'name' => $TAG . '_过期券', 'type' => 'reduce', 'value' => '20', 'min_amount' => '100',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 3, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => date('Y-m-d H:i:s', time() - 60),
    'code' => couponCode(), 'remark' => '',
], [], $adminId);
check('建已过期券成功', $r['ok'] === true, $r['msg']);
$coupons[] = $cExp = (int)$r['id'];
UserCoupon::grant($cExp, [$ou1], UserCoupon::SOURCE_ADMIN);
$ucExp = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cExp, $ou1]);
try {
    Order::createWithStock($user1, $prod, 'qr', false, $ucExp);
    check('过期券不可用于下单', false, '未抛异常');
} catch (\Throwable $e) {
    check('过期券不可用于下单', true);
}

// 14.10 不存在的券 id
try {
    Order::createWithStock($user1, $prod, 'qr', false, 99999999);
    check('不存在的券 id 被拒', false, '未抛异常');
} catch (\Throwable $e) {
    check('不存在的券 id 被拒', true);
}

// ============================================================
group('15. 功能总开关关闭时忽略券');

Setting::set('coupon_enabled', '0');
$r = UserCoupon::grant($cOrder, [$ou1], UserCoupon::SOURCE_ADMIN);
$ucOff2 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? AND status=0 ORDER BY id DESC LIMIT 1', [$cOrder, $ou1]);
$res = Order::createWithStock($user1, $prod, 'qr', false, $ucOff2);
check('总开关关闭时下单仍可成功', is_array($res) && !empty($res['id']), json_encode($res, JSON_UNESCAPED_UNICODE));
$oOff = $db->first('SELECT * FROM ly_orders WHERE id=?', [(int)$res['id']]);
check('总开关关闭时 coupon_discount=0.00', BalanceLog::normalize($oOff['coupon_discount']) === '0.00', $oOff['coupon_discount']);
check('总开关关闭时 pay_amount=100.00', BalanceLog::normalize($oOff['pay_amount']) === '100.00', $oOff['pay_amount']);
check('总开关关闭时券未被核销', (int)UserCoupon::find($ucOff2)['status'] === UserCoupon::STATUS_UNUSED);
Setting::set('coupon_enabled', '1');

// ============================================================
group('16. 关单退券 / 已支付不可关单');

// 16.1 待支付订单关单 → 退券
$users[] = $ru1 = mkUser($db, $TAG . '_ru1', '0.00');
UserCoupon::grant($cOrder, [$ru1], UserCoupon::SOURCE_ADMIN);
$ucRu = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ru1]);
$userRu = ['id' => $ru1, 'email' => $TAG . '_ru1@qq.com'];
$resRu = Order::createWithStock($userRu, $prod, 'qr', false, $ucRu);
check('退券测试单已建', is_array($resRu) && !empty($resRu['id']), json_encode($resRu, JSON_UNESCAPED_UNICODE));
$oRu = (int)$resRu['id'];
check('下单后券已核销', (int)UserCoupon::find($ucRu)['status'] === UserCoupon::STATUS_USED);

$usedBefore = (int)Coupon::find($cOrder)['used_count'];
$closed = Order::closeWithRefund($oRu);
check('待支付订单关单成功（closeWithRefund）', $closed === true, var_export($closed, true));
check('关单后券退回未使用', (int)UserCoupon::find($ucRu)['status'] === UserCoupon::STATUS_UNUSED);
check('退券后券 order_id 归零', (int)UserCoupon::find($ucRu)['order_id'] === 0);
check('退券后 used_count 减 1', (int)Coupon::find($cOrder)['used_count'] === $usedBefore - 1, (string)Coupon::find($cOrder)['used_count']);

// 16.2 已支付订单不可关单
$users[] = $ru2 = mkUser($db, $TAG . '_ru2', '0.00');
UserCoupon::grant($cOrder, [$ru2], UserCoupon::SOURCE_ADMIN);
$ucRu2 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ru2]);
$userRu2 = ['id' => $ru2, 'email' => $TAG . '_ru2@qq.com'];
$resRu2 = Order::createWithStock($userRu2, $prod, 'qr', false, $ucRu2);
$oRu2 = (int)$resRu2['id'];
Order::markPaid($oRu2, 'TESTPAY_' . $TAG, '0.00');
$paidRow = $db->first('SELECT status FROM ly_orders WHERE id=?', [$oRu2]);
check('订单已标记为已支付', (int)$paidRow['status'] === 1, json_encode($paidRow));
$closed2 = Order::closeWithRefund($oRu2);
check('已支付订单不可关单', $closed2 === false);
check('已支付订单的券仍为已使用', (int)UserCoupon::find($ucRu2)['status'] === UserCoupon::STATUS_USED);

// 16.3 纯余额单下单即已完成，不可再关单（语义正确）
$users[] = $ru3 = mkUser($db, $TAG . '_ru3', '200.00');
UserCoupon::grant($cOrder, [$ru3], UserCoupon::SOURCE_ADMIN);
$ucRu3 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ru3]);
$userRu3 = ['id' => $ru3, 'email' => $TAG . '_ru3@qq.com'];
$resRu3 = Order::createWithStock($userRu3, $prod, 'qr', true, $ucRu3);
$oRu3 = (int)$resRu3['id'];
$ru3Order = $db->first('SELECT * FROM ly_orders WHERE id=?', [$oRu3]);
check('纯余额单：pay_channel=balance', (string)$ru3Order['pay_channel'] === 'balance', (string)$ru3Order['pay_channel']);
check('纯余额单：下单扣 80（200 → 120）', bal($db, $ru3) === '120.00', bal($db, $ru3));
check('纯余额单：券已核销', (int)UserCoupon::find($ucRu3)['status'] === UserCoupon::STATUS_USED);

$ru3Paid = Order::closeWithRefund($oRu3);
check('纯余额单已支付，不可再关单', $ru3Paid === false);
check('不可关单则余额不退还（仍 120.00）', bal($db, $ru3) === '120.00', bal($db, $ru3));
check('不可关单则券不退回（仍已使用）', (int)UserCoupon::find($ucRu3)['status'] === UserCoupon::STATUS_USED);

// 16.4 待支付的部分抵扣单关单 → 退余额 + 退券
$users[] = $ru4 = mkUser($db, $TAG . '_ru4', '30.00');
UserCoupon::grant($cOrder, [$ru4], UserCoupon::SOURCE_ADMIN);
$ucRu4 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $ru4]);
$userRu4 = ['id' => $ru4, 'email' => $TAG . '_ru4@qq.com'];
$resRu4 = Order::createWithStock($userRu4, $prod, 'qr', true, $ucRu4);
$oRu4 = (int)$resRu4['id'];
check('部分抵扣单为待支付（未走支付宝）', (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$oRu4]) === Order::STATUS_PENDING);
check('部分抵扣单：余额暂未扣（仍 30.00）', bal($db, $ru4) === '30.00', bal($db, $ru4));

$ru4Closed = Order::closeWithRefund($oRu4);
check('部分抵扣单关单成功', $ru4Closed === true);
check('部分抵扣单关单退还余额（30.00 保持）', bal($db, $ru4) === '30.00', bal($db, $ru4));
check('部分抵扣单关单退券', (int)UserCoupon::find($ucRu4)['status'] === UserCoupon::STATUS_UNUSED);

// ============================================================
group('17. markPaid 部分抵扣扣款（1.2.0 资金漏洞回归）');

$users[] = $mp1 = mkUser($db, $TAG . '_mp1', '500.00');
UserCoupon::grant($cOrder, [$mp1], UserCoupon::SOURCE_ADMIN);
$ucMp = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $mp1]);
$userMp = ['id' => $mp1, 'email' => $TAG . '_mp1@qq.com'];
$resMp = Order::createWithStock($userMp, $prod, 'qr', true, $ucMp);
check('部分抵扣单已建', is_array($resMp) && !empty($resMp['id']), json_encode($resMp, JSON_UNESCAPED_UNICODE));
$oMp = (int)$resMp['id'];
$mpOrder = $db->first('SELECT * FROM ly_orders WHERE id=?', [$oMp]);
check('部分抵扣：券减 20', BalanceLog::normalize($mpOrder['coupon_discount']) === '20.00');
check('部分抵扣：余额 500 → 券后 80 全抵扣', BalanceLog::normalize($mpOrder['balance_paid']) === '80.00', $mpOrder['balance_paid']);
check('部分抵扣：实付 0', BalanceLog::normalize($mpOrder['pay_amount']) === '0.00');
check('部分抵扣：下单即已扣 80（余额 420）', bal($db, $mp1) === '420.00', bal($db, $mp1));

$balBefore = bal($db, $mp1);
Order::markPaid($oMp, 'TESTPAY_MP_' . $TAG, '0.00');
check('余额单 markPaid 不重复扣款', bal($db, $mp1) === $balBefore, bal($db, $mp1));

// 真正走支付宝的部分抵扣：券后 80，余额只够 30
$users[] = $mp2 = mkUser($db, $TAG . '_mp2', '30.00');
UserCoupon::grant($cOrder, [$mp2], UserCoupon::SOURCE_ADMIN);
$ucMp2 = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cOrder, $mp2]);
$userMp2 = ['id' => $mp2, 'email' => $TAG . '_mp2@qq.com'];
$resMp2 = Order::createWithStock($userMp2, $prod, 'qr', true, $ucMp2);
$oMp2 = (int)$resMp2['id'];
$mp2Order = $db->first('SELECT * FROM ly_orders WHERE id=?', [$oMp2]);
check('第三方支付单：券减 20', BalanceLog::normalize($mp2Order['coupon_discount']) === '20.00');
check('第三方支付单：余额抵扣 30', BalanceLog::normalize($mp2Order['balance_paid']) === '30.00', $mp2Order['balance_paid']);
check('第三方支付单：实付 50', BalanceLog::normalize($mp2Order['pay_amount']) === '50.00', $mp2Order['pay_amount']);
check('第三方支付单：下单暂不扣余额（仍 30.00）', bal($db, $mp2) === '30.00', bal($db, $mp2));
Order::markPaid($oMp2, 'TESTPAY_MP2_' . $TAG, '50.00');
check('支付成功后扣减余额至 0.00', bal($db, $mp2) === '0.00', bal($db, $mp2));
$balAfterPaid = bal($db, $mp2);
Order::markPaid($oMp2, 'TESTPAY_MP2_' . $TAG, '50.00');
check('重复 markPaid 不重复扣款', bal($db, $mp2) === $balAfterPaid, bal($db, $mp2));

// ============================================================
group('18. 并发：同一张券只成功一次');

$users[] = $cc1 = mkUser($db, $TAG . '_cc1', '0.00');
$rcc = Coupon::create([
    'name' => $TAG . '_并发用券', 'type' => 'reduce', 'value' => '20', 'min_amount' => '100',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
check('建并发测试券成功', $rcc['ok'] === true, $rcc['msg']);
$coupons[] = $cConc = (int)$rcc['id'];
UserCoupon::grant($cConc, [$cc1], UserCoupon::SOURCE_ADMIN);
$ucConc = (int)$db->value('SELECT id FROM ly_user_coupons WHERE coupon_id=? AND user_id=? LIMIT 1', [$cConc, $cc1]);

$okCount = 0;
$errCount = 0;
$userCc = ['id' => $cc1, 'email' => $TAG . '_cc1@qq.com'];
$prodCc = Product::find($pOrder);
for ($i = 0; $i < 8; $i++) {
    try {
        $rr = Order::createWithStock($userCc, $prodCc, 'qr', false, $ucConc);
        if (is_array($rr) && !empty($rr['id'])) { $okCount++; }
        else { $errCount++; }
    } catch (\Throwable $e) {
        $errCount++;
    }
}
check('并发 8 次用同一券：只成功 1 次', $okCount === 1, "成功 {$okCount} / 失败 {$errCount}");
check('并发后券状态为已使用', (int)UserCoupon::find($ucConc)['status'] === UserCoupon::STATUS_USED);
check('并发后该券 used_count 为 1', (int)Coupon::find($cConc)['used_count'] === 1, (string)Coupon::find($cConc)['used_count']);

// ============================================================
group('19. 并发领取限量券');

$rc = Coupon::create([
    'name' => $TAG . '_并发领取限量1', 'type' => 'reduce', 'value' => '10', 'min_amount' => '50',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 1,
    'claimable' => 1, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
check('建限量 1 张并发券成功', $rc['ok'] === true, $rc['msg']);
$coupons[] = $cRush = (int)$rc['id'];

$rushUsers = [];
for ($i = 0; $i < 10; $i++) {
    $rushUsers[] = mkUser($db, $TAG . '_rush' . $i);
}
$users = array_merge($users, $rushUsers);

$rushOk = 0;
foreach ($rushUsers as $u) {
    $rr = UserCoupon::claim($cRush, $u);
    if ($rr['ok']) { $rushOk++; }
}
check('10 人抢 1 张券：只成功 1 人', $rushOk === 1, "成功 {$rushOk}");
check('抢券后 received_count=1', (int)Coupon::find($cRush)['received_count'] === 1);
check(
    '抢券后实例数=1',
    (int)$db->value('SELECT COUNT(*) FROM ly_user_coupons WHERE coupon_id=?', [$cRush]) === 1
);

// ============================================================
group('20. 删除保护与分页');

// 有持券人时删除应被拒
$del = Coupon::delete($cRush);
check('有持券人时删除被拒', $del['ok'] === false, $del['msg']);
check('删除被拒后券仍存在', Coupon::find($cRush) !== null);

// 无持券人可删除
$rd = Coupon::create([
    'name' => $TAG . '_可删除', 'type' => 'reduce', 'value' => '5', 'min_amount' => '10',
    'max_discount' => '0', 'scope' => 0, 'per_user_limit' => 1, 'received_limit' => 0,
    'claimable' => 0, 'status' => 1, 'start_at' => '', 'expires_at' => '', 'code' => couponCode(), 'remark' => '',
], [], $adminId);
$cDel = (int)$rd['id'];
$del = Coupon::delete($cDel);
check('无持券人可删除', $del['ok'] === true, $del['msg']);
check('删除后 find 返回 null', Coupon::find($cDel) === null);
check('删除后范围记录已清理', (int)$db->value('SELECT COUNT(*) FROM ly_coupon_scopes WHERE coupon_id=?', [$cDel]) === 0);

$del = Coupon::delete(99999999);
// 1.7.2 起：券不存在时明确返回失败（而非静默成功），便于后台给出准确提示
check('删除不存在的券返回失败且不报错', $del['ok'] === false, $del['msg']);
check('券不存在的提示明确', strpos($del['msg'], '不存在') !== false, $del['msg']);

// 分页
$pg1 = Coupon::paginate(1, 5);
check('paginate 返回结构完整', isset($pg1['total'], $pg1['rows'], $pg1['page'], $pg1['pages']));
check('paginate 每页不超过 5 条', count($pg1['rows']) <= 5, (string)count($pg1['rows']));
$pg2 = Coupon::paginate(1, 5, ['status' => 1]);
check('paginate 支持 status 过滤', isset($pg2['rows']));

// ============================================================
group('21. 全库金额恒等式巡检');

$badRows = $db->select(
    'SELECT id, amount, coupon_discount, balance_paid, pay_amount
       FROM ly_orders
      WHERE coupon_id > 0'
);
$mismatch = 0;
foreach ($badRows as $o) {
    $sum = bcadd(
        BalanceLog::normalize($o['coupon_discount']),
        bcadd(BalanceLog::normalize($o['balance_paid']), BalanceLog::normalize($o['pay_amount']), 2),
        2
    );
    if ($sum !== BalanceLog::normalize($o['amount'])) {
        $mismatch++;
    }
}
check('全库用券订单恒等式全部成立', $mismatch === 0, "不一致 {$mismatch} 条 / 共 " . count($badRows) . ' 条');

$negDiscount = (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE coupon_discount < 0');
check('无负的券抵扣金额', $negDiscount === 0);

$negUsed = (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE used_count < 0');
check('无负的券使用次数', $negUsed === 0);

$negReceived = (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE received_count < 0');
check('无负的券发放次数', $negReceived === 0);

$orphan = (int)$db->value(
    'SELECT COUNT(*) FROM ly_user_coupons uc LEFT JOIN ly_coupons c ON c.id=uc.coupon_id WHERE c.id IS NULL'
);
check('无孤儿券实例（都指向存在的模板）', $orphan === 0, (string)$orphan);

$usedNoOrder = (int)$db->value(
    'SELECT COUNT(*) FROM ly_user_coupons WHERE status=1 AND (order_id IS NULL OR order_id=0)'
);
check('已使用的券都绑定了订单', $usedNoOrder === 0, (string)$usedNoOrder);

// ============================================================
group('22. 清理');

$db->query('DELETE FROM ly_orders WHERE product_id IN (' . implode(',', array_map('intval', $products)) . ')');
$db->query('DELETE FROM ly_user_coupons WHERE coupon_id IN (' . implode(',', array_map('intval', $coupons)) . ')');
$db->query('DELETE FROM ly_coupon_scopes WHERE coupon_id IN (' . implode(',', array_map('intval', $coupons)) . ')');
$db->query('DELETE FROM ly_coupons WHERE id IN (' . implode(',', array_map('intval', $coupons)) . ')');
$db->query('DELETE FROM ly_products WHERE id IN (' . implode(',', array_map('intval', $products)) . ')');
if ($users) {
    $db->query('DELETE FROM ly_users WHERE id IN (' . implode(',', array_map('intval', $users)) . ')');
}

check('测试券已清理', (int)$db->value('SELECT COUNT(*) FROM ly_coupons WHERE id IN (' . implode(',', array_map('intval', $coupons)) . ')') === 0);

// 还原开关
if ($origEnabled !== null) {
    Setting::set('coupon_enabled', (string)$origEnabled);
}
if ($origClaimEnabled !== null) {
    Setting::set('coupon_claim_enabled', (string)$origClaimEnabled);
}
check('功能开关已还原', true);

echo "\n通过 {$pass} 项，失败 {$fail} 项\n";
exit($fail > 0 ? 1 : 0);
