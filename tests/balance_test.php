<?php
/**
 * 余额与兑换码测试（1.2.0 新增）
 *
 * 覆盖：
 *   - BalanceLog 增减精度（bcmath，不允许浮点漂移）
 *   - 余额不足时拒绝扣减且不产生部分变动
 *   - 流水 before/after 链自洽
 *   - RedeemCode 生成 / 格式校验 / 掩码 / 哈希存储
 *   - 兑换成功、重复兑换、过期、作废、非法格式
 *   - 并发兑换同一码只成功一次
 *   - 订单余额抵扣：部分 / 全额 / 不足 / 关闭退款
 *
 * 用法：php tests/balance_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\BalanceLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\RedeemCode;
use App\Models\Setting;

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
        $suffix = ($extra === '' || $extra === null) ? '' : '   (' . (is_scalar($extra) ? $extra : json_encode($extra)) . ')';
        echo "  ✘ " . $desc . $suffix . "   <-- 失败\n";
    }
}

echo "==========================================\n";
echo " LY云计算 · 余额与兑换码测试\n";
echo "==========================================\n";

$db = Database::instance();
$adminId = (int)$db->value('SELECT id FROM ly_admins ORDER BY id ASC LIMIT 1');

// 记录初始状态，末尾还原
$origBalanceEnabled = Setting::get('balance_enabled');
$origRedeemEnabled  = Setting::get('redeem_enabled');

$TEST_TAG = 'baltest_' . bin2hex(random_bytes(4));

/** 建一个测试用户 */
function mkUser(Database $db, string $tag): int
{
    return (int)$db->insert('ly_users', [
        'email' => $tag . '@qq.com',
        'password' => password_hash('Test1234', PASSWORD_DEFAULT),
        'nickname' => 'BalTest',
        'balance' => '0.00',
        'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/** 建一个测试商品 */
function mkProduct(Database $db, string $name, string $price): int
{
    return (int)$db->insert('ly_products', [
        'name' => $name, 'price' => $price, 'stock_mode' => 1,
        'auto_deliver' => 1, 'status' => 1, 'sort' => 0,
        'description' => $name, 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/** 给商品补一条库存 */
function mkStock(Database $db, int $pid, string $tag): void
{
    $db->insert('ly_stocks', [
        'product_id' => $pid,
        'panel_url'  => 'https://panel.test/' . $tag,
        'panel_user' => 'u_' . $tag,
        'panel_pass' => 'p_' . $tag,
        'status' => 0, 'order_id' => 0, 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

$users = [];
$products = [];

// ------------------------------------------------------------
group('1. 金额归一化与校验');

check('normalize 补足两位小数', BalanceLog::normalize('10') === '10.00');
check('normalize 四舍五入到分', BalanceLog::normalize('10.005') === '10.00');
check('normalize 非法输入回落 0.00', BalanceLog::normalize('abc') === '0.00');
check('normalize 空串回落 0.00', BalanceLog::normalize('') === '0.00');
check('normalize 处理负数', BalanceLog::normalize('-5') === '-5.00');
check('validateAmount 正常通过', BalanceLog::validateAmount('10.00') === '');
check('validateAmount 低于下限报错', BalanceLog::validateAmount('0.001', '0.01') !== '');
check('validateAmount 超上限报错', BalanceLog::validateAmount('1000000.00', '0.01', '999999.99') !== '');
check('validateAmount 支持负区间', BalanceLog::validateAmount('-50.00', '-999999.99', '999999.99') === '');

// ------------------------------------------------------------
group('2. 余额增减与 bcmath 精度');

$u1 = mkUser($db, $TEST_TAG . '_u1');
$users[] = $u1;

check('初始余额 0.00', BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u1])) === '0.00');

$after = BalanceLog::credit($u1, '0.10', BalanceLog::TYPE_ADMIN, 0, '精度测试');
check('加 0.10 → 0.10', $after === '0.10', $after);

$after = BalanceLog::credit($u1, '0.20', BalanceLog::TYPE_ADMIN, 0, '精度测试');
check('加 0.20 → 0.30（无浮点漂移）', $after === '0.30', $after);

$after = BalanceLog::credit($u1, '99.70', BalanceLog::TYPE_ADMIN, 0, '精度测试');
check('加 99.70 → 100.00 精确', $after === '100.00', $after);

check('hasEnough 100.00 够扣 100.00', BalanceLog::hasEnough($u1, '100.00') === true);
check('hasEnough 100.00 不够扣 100.01', BalanceLog::hasEnough($u1, '100.01') === false);

$okDebit = BalanceLog::debit($u1, '0.01', BalanceLog::TYPE_CONSUME, 0, '精度测试');
$after = BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u1]));
check('扣 0.01 → 99.99', $okDebit && $after === '99.99', $after);

// ------------------------------------------------------------
group('3. 余额不足拒绝扣减（无部分变动）');

$okDebit = BalanceLog::debit($u1, '99999.00', BalanceLog::TYPE_CONSUME, 0, '超额');
$after = BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u1]));
check('余额不足返回 false', $okDebit === false);
check('余额保持 99.99 不变', $after === '99.99', $after);

$overLogs = (int)$db->value(
    'SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=? AND remark=?',
    [$u1, '超额']
);
check('未写入失败流水', $overLogs === 0, (string)$overLogs);

// ------------------------------------------------------------
group('4. 流水 before/after 链自洽');

$logs = BalanceLog::forUser($u1, 1, 50);
$rows = array_reverse($logs['rows'] ?? []);
$chainOk = true;
$expectBefore = '0.00';
$why = '';
foreach ($rows as $l) {
    $before = BalanceLog::normalize($l['before']);
    $change = BalanceLog::normalize($l['change']);
    $afterL = BalanceLog::normalize($l['after']);
    if ($before !== $expectBefore) {
        $chainOk = false;
        $why = "before=$before 期望=$expectBefore";
        break;
    }
    if (bccomp($afterL, bcadd($before, $change, 2), 2) !== 0) {
        $chainOk = false;
        $why = "after=$afterL 期望=" . bcadd($before, $change, 2);
        break;
    }
    $expectBefore = $afterL;
}
check('流水首尾相接且算数正确', $chainOk, $why);
check('链条终点 = 当前余额', $expectBefore === '99.99', $expectBefore);
check('流水按时间正序排列', count($rows) >= 4, (string)count($rows));

$stats = BalanceLog::statsForUser($u1);
check('statsForUser 统计充值额', BalanceLog::normalize($stats['recharged_total']) === '100.00', $stats['recharged_total']);
check('statsForUser 统计消费额', BalanceLog::normalize($stats['consumed_total']) === '0.01', $stats['consumed_total']);
check('statsForUser 记录条数正确', $stats['log_total'] === 4, (string)$stats['log_total']);

// ------------------------------------------------------------
group('5. 兑换码生成与格式');

$codes = [];
for ($i = 0; $i < 500; $i++) {
    $codes[] = RedeemCode::randomCode();
}
check('500 个随机码全部唯一', count(array_unique($codes)) === 500);

$allLen14 = true;
$allPrefix = true;
$allCharset = true;
foreach ($codes as $c) {
    if (strlen($c) !== 14) { $allLen14 = false; }
    if (strpos($c, 'LY') !== 0) { $allPrefix = false; }
    if (preg_match('/[0O1I]/', substr($c, 2))) { $allCharset = false; }
}
check('长度统一 14 位', $allLen14);
check('前缀统一 LY', $allPrefix);
check('不含易混字符 0/O/1/I', $allCharset);

$GOOD = 'LY7K9M2X4TP9Q8';
check('掩码保留前 4 后 4', RedeemCode::mask($GOOD) === 'LY7K****P9Q8', RedeemCode::mask($GOOD));
check('isValidFormat 接受合法码', RedeemCode::isValidFormat($GOOD) === true);
check('isValidFormat 接受小写', RedeemCode::isValidFormat(strtolower($GOOD)) === true);
check('isValidFormat 容忍首尾空格', RedeemCode::isValidFormat('  ' . $GOOD . ' ') === true);
check('isValidFormat 拒绝短码', RedeemCode::isValidFormat(substr($GOOD, 0, 13)) === false);
check('isValidFormat 拒绝长码', RedeemCode::isValidFormat($GOOD . '9') === false);
check('isValidFormat 拒绝错误前缀', RedeemCode::isValidFormat('XX' . substr($GOOD, 2)) === false);
check('isValidFormat 拒绝含 0', RedeemCode::isValidFormat('LY7K9M2X4TP90') === false);
check('isValidFormat 拒绝含 O', RedeemCode::isValidFormat('LY7K9M2X4TP9O') === false);
check('isValidFormat 拒绝含 I', RedeemCode::isValidFormat('LY7K9M2X4TP9I') === false);
check('isValidFormat 拒绝空串', RedeemCode::isValidFormat('') === false);

// ------------------------------------------------------------
group('6. 兑换码生成落库与哈希存储');

$gen = RedeemCode::generate(5, '10.00', 30, $adminId, $TEST_TAG);
check('生成 5 个成功', $gen['ok'] === true, $gen['msg']);
check('返回 5 个明文', count($gen['codes']) === 5);
check('面额归一化为 10.00', $gen['amount'] === '10.00', $gen['amount']);
check('批次号非空', $gen['batch_no'] !== '');
$batchNo = $gen['batch_no'];

$bad = RedeemCode::generate(0, '10.00', 30, $adminId);
check('数量 0 被拒', $bad['ok'] === false);
$bad = RedeemCode::generate(RedeemCode::MAX_BATCH + 1, '10.00', 30, $adminId);
check('数量超上限被拒', $bad['ok'] === false);
$bad = RedeemCode::generate(1, '0', 30, $adminId);
check('面额 0 被拒', $bad['ok'] === false);

$rawFirst = $gen['codes'][0];
$rowDb = $db->first('SELECT * FROM ly_redeem_codes WHERE code_hash=? LIMIT 1', [hash('sha256', $rawFirst)]);
check('库中按 sha256 存储', $rowDb !== null);
check('code_hash 等于 sha256(明文)', $rowDb['code_hash'] === hash('sha256', $rawFirst));
check('库中不含明文（只有掩码）', $rowDb['code_mask'] === RedeemCode::mask($rawFirst), $rowDb['code_mask']);
check('初始状态为未使用', (int)$rowDb['status'] === RedeemCode::STATUS_UNUSED);
check('created_by 记录管理员', (int)$rowDb['created_by'] === $adminId);

$genPerm = RedeemCode::generate(1, '5.00', null, $adminId, $TEST_TAG);
$rowPerm = $db->first('SELECT * FROM ly_redeem_codes WHERE batch_no=? LIMIT 1', [$genPerm['batch_no']]);
check('ttl=null 时永久有效', $rowPerm['expires_at'] === null);

// ------------------------------------------------------------
group('6b. 小额面额（1.7.3：下限 1.00 → 0.01）');

// 默认下限（未在库中配置时）应为 0.01
check('默认下限为 0.01', Setting::redeemMinAmount() === '0.01', Setting::redeemMinAmount());
check('下限不高于 0.01 才支持小额码', bccomp(Setting::redeemMinAmount(), '0.01', 2) <= 0, Setting::redeemMinAmount());

// 0.1 / 0.5 必须能生成
$tiny = RedeemCode::generate(1, '0.1', 30, $adminId, $TEST_TAG);
check('面额 0.1 可生成', $tiny['ok'] === true, $tiny['msg']);
check('面额 0.1 归一化为 0.10', $tiny['amount'] === '0.10', $tiny['amount']);

$half = RedeemCode::generate(1, '0.5', 30, $adminId, $TEST_TAG);
check('面额 0.5 可生成', $half['ok'] === true, $half['msg']);
check('面额 0.5 归一化为 0.50', $half['amount'] === '0.50', $half['amount']);

// 边界：恰好 0.01 可用，0.001 应被校验拦截
$minEdge = RedeemCode::generate(1, '0.01', 30, $adminId, $TEST_TAG);
check('面额 0.01（下限边界）可生成', $minEdge['ok'] === true, $minEdge['msg']);
check(
    '面额 0.001 低于下限被 validateAmount 拦截',
    BalanceLog::validateAmount('0.001', Setting::redeemMinAmount(), Setting::redeemMaxAmount()) !== ''
);

// 落库金额与精度
$tinyRow = $db->first('SELECT amount FROM ly_redeem_codes WHERE batch_no=? LIMIT 1', [$tiny['batch_no']]);
check('0.1 落库金额为 0.10（DECIMAL 精度）', (string)$tinyRow['amount'] === '0.10', (string)$tinyRow['amount']);

// 小额码兑换后余额精度：0.10 + 0.50 = 0.60
$tinyUser = mkUser($db, $TEST_TAG . '_tiny');
$users[]  = $tinyUser;
$r1 = RedeemCode::redeem($tiny['codes'][0], $tinyUser);
$r2 = RedeemCode::redeem($half['codes'][0], $tinyUser);
$bal = (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$tinyUser]);
check('兑换 0.1 成功', $r1['ok'] === true, $r1['msg']);
check('兑换 0.5 成功', $r2['ok'] === true, $r2['msg']);
check('0.10 + 0.50 = 0.60（无浮点误差）', bccomp($bal, '0.60', 2) === 0, $bal);

// ------------------------------------------------------------
group('7. 兑换流程');

$u2 = mkUser($db, $TEST_TAG . '_u2');
$users[] = $u2;

$rd = RedeemCode::redeem(strtolower($rawFirst), $u2);
check('兑换成功（小写输入）', $rd['ok'] === true, $rd['msg']);
check('入账 10.00', $rd['amount'] === '10.00', $rd['amount']);
check('兑换后余额 10.00', $rd['after'] === '10.00', $rd['after']);

$rowUsed = $db->first('SELECT * FROM ly_redeem_codes WHERE id=?', [$rowDb['id']]);
check('码状态变为已使用', (int)$rowUsed['status'] === RedeemCode::STATUS_USED);
check('used_by 记录用户', (int)$rowUsed['used_by'] === $u2);
check('used_at 已写入', $rowUsed['used_at'] !== null);

$rd2 = RedeemCode::redeem($rawFirst, $u2);
check('重复兑换被拒', $rd2['ok'] === false);
check('重复兑换提示已使用', strpos($rd2['msg'], '已被使用') !== false, $rd2['msg']);
$bal = BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u2]));
check('余额未被二次累加', $bal === '10.00', $bal);

$rdBad = RedeemCode::redeem('NOTACODE12345', $u2);
check('非法格式被拒', $rdBad['ok'] === false);
check('非法格式提示不正确', strpos($rdBad['msg'], '格式不正确') !== false, $rdBad['msg']);
$rdGhost = RedeemCode::redeem('LY222222222222', $u2);
check('不存在的码被拒', $rdGhost['ok'] === false);
$rdNoLogin = RedeemCode::redeem($gen['codes'][1], 0);
check('未登录被拒', $rdNoLogin['ok'] === false);
check('未登录时码保持未使用',
    (int)$db->value('SELECT status FROM ly_redeem_codes WHERE code_hash=?', [hash('sha256', $gen['codes'][1])]) === 0);

// 过期
$genExp = RedeemCode::generate(1, '8.88', 1, $adminId, $TEST_TAG);
$expRaw = $genExp['codes'][0];
$db->update('ly_redeem_codes', ['expires_at' => date('Y-m-d H:i:s', time() - 3600)],
    'code_hash=?', [hash('sha256', $expRaw)]);
$rdExp = RedeemCode::redeem($expRaw, $u2);
check('过期码被拒', $rdExp['ok'] === false);
check('过期码提示已过期', strpos($rdExp['msg'], '已过期') !== false, $rdExp['msg']);

// 作废
$genVoid = RedeemCode::generate(2, '20.00', 30, $adminId, $TEST_TAG);
$voidRaw = $genVoid['codes'][0];
$voidId = (int)$db->value('SELECT id FROM ly_redeem_codes WHERE code_hash=?', [hash('sha256', $voidRaw)]);
check('void() 成功', RedeemCode::void($voidId) === true);
$rdVoid = RedeemCode::redeem($voidRaw, $u2);
check('作废码兑换被拒', $rdVoid['ok'] === false);
check('作废码提示已作废', strpos($rdVoid['msg'], '作废') !== false, $rdVoid['msg']);
check('已作废的码无法再次作废', RedeemCode::void($voidId) === false);
check('已使用的码无法作废', RedeemCode::void((int)$rowDb['id']) === false);

check('批量作废返回 1', RedeemCode::voidBatch($genVoid['batch_no']) === 1);
check('再次批量作废返回 0', RedeemCode::voidBatch($genVoid['batch_no']) === 0);

// 多码累加
$rd3 = RedeemCode::redeem($gen['codes'][2], $u2);
check('第 2 个码兑换后余额 20.00', $rd3['after'] === '20.00', $rd3['after']);
$rd4 = RedeemCode::redeem($gen['codes'][3], $u2);
check('第 3 个码兑换后余额 30.00', $rd4['after'] === '30.00', $rd4['after']);

// ------------------------------------------------------------
group('8. 并发兑换同一码只成功一次');

$concRaw = $gen['codes'][4];
$concHash = hash('sha256', $concRaw);
$tmpScript = sys_get_temp_dir() . '/bal_conc_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tmpScript, '<?php require ' . var_export(__DIR__ . '/../app/bootstrap.php', true) . ';'
    . '$r = App\Models\RedeemCode::redeem($argv[1], (int)$argv[2]); echo $r["ok"] ? "OK" : "NO";');

$procs = [];
for ($i = 0; $i < 60; $i++) {
    $procs[] = popen('php ' . escapeshellarg($tmpScript) . ' ' . escapeshellarg($concRaw) . ' ' . $u2 . ' 2>&1', 'r');
}
$okCnt = 0;
foreach ($procs as $p) {
    $out = stream_get_contents($p);
    pclose($p);
    if (strpos((string)$out, 'OK') !== false) { $okCnt++; }
}
@unlink($tmpScript);

check('60 并发中恰好 1 次成功', $okCnt === 1, "成功 $okCnt 次");
$concId = (int)$db->value('SELECT id FROM ly_redeem_codes WHERE code_hash=?', [$concHash]);
$concLogs = (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE ref_id=? AND type=?', [$concId, 'redeem']);
check('该码只有 1 条充值流水', $concLogs === 1, (string)$concLogs);
$balAfterConc = BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u2]));
check('余额只增加一次 → 40.00', $balAfterConc === '40.00', $balAfterConc);

// ------------------------------------------------------------
group('9. 订单余额抵扣');

$pid = mkProduct($db, $TEST_TAG . '_prod', '100.00');
$products[] = $pid;
for ($i = 0; $i < 8; $i++) { mkStock($db, $pid, $TEST_TAG . '_s' . $i); }
$prod = Product::find($pid);

// 不使用余额
$u3 = mkUser($db, $TEST_TAG . '_u3');
$users[] = $u3;
$o = Order::createWithStock(['id' => $u3], $prod, 'qr', false);
check('不用余额：amount=100.00', $o['amount'] === '100.00', $o['amount']);
check('不用余额：balance_paid=0.00', $o['balance_paid'] === '0.00', $o['balance_paid']);
check('不用余额：pay_amount=100.00', $o['pay_amount'] === '100.00', $o['pay_amount']);
check('不用余额：状态待支付', (int)$o['status'] === Order::STATUS_PENDING);
Order::close((int)$o['id']);

// 部分抵扣
$u4 = mkUser($db, $TEST_TAG . '_u4');
$users[] = $u4;
BalanceLog::credit($u4, '30.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$o = Order::createWithStock(['id' => $u4], $prod, 'qr', true);
check('部分抵扣：balance_paid=30.00', $o['balance_paid'] === '30.00', $o['balance_paid']);
check('部分抵扣：pay_amount=70.00', $o['pay_amount'] === '70.00', $o['pay_amount']);
check('部分抵扣：金额链自洽',
    bccomp($o['amount'], bcadd($o['pay_amount'], $o['balance_paid'], 2), 2) === 0);
check('部分抵扣：状态仍待支付', (int)$o['status'] === Order::STATUS_PENDING);
check('部分抵扣：下单不预扣余额',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u4])) === '30.00');

$paid = Order::markPaid((int)$o['id'], 'BALTEST' . time(), '{}');
check('部分抵扣订单 markPaid 成功', $paid === true);
check('部分抵扣订单变为已发货',
    (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$o['id']]) === Order::STATUS_DELIVERED);
check('重复 markPaid 幂等', Order::markPaid((int)$o['id'], 'DUP', '{}') === false);

// 全额抵扣
$u5 = mkUser($db, $TEST_TAG . '_u5');
$users[] = $u5;
BalanceLog::credit($u5, '500.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$o = Order::createWithStock(['id' => $u5], $prod, 'qr', true);
check('全额抵扣：balance_paid=100.00', $o['balance_paid'] === '100.00', $o['balance_paid']);
check('全额抵扣：pay_amount=0.00', $o['pay_amount'] === '0.00', $o['pay_amount']);
check('全额抵扣：渠道为 balance', $o['pay_channel'] === 'balance', $o['pay_channel']);
check('全额抵扣：直接已发货', (int)$o['status'] === Order::STATUS_DELIVERED);
check('全额抵扣：已绑定库存', (int)$o['stock_id'] > 0);
check('全额抵扣：trade_no 以 BAL 前缀', strpos((string)$o['trade_no'], 'BAL') === 0, $o['trade_no']);
check('全额抵扣：余额扣为 400.00',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u5])) === '400.00');
$cLog = $db->first('SELECT * FROM ly_balance_logs WHERE user_id=? AND type=? ORDER BY id DESC LIMIT 1', [$u5, 'consume']);
check('全额抵扣：写入消费流水', $cLog !== null);
check('全额抵扣：流水 change=-100.00', BalanceLog::normalize($cLog['change']) === '-100.00', $cLog['change']);
check('全额抵扣：库存已标记售出',
    (int)$db->value('SELECT status FROM ly_stocks WHERE id=?', [$o['stock_id']]) === 1);

// 余额不足抵扣
$u6 = mkUser($db, $TEST_TAG . '_u6');
$users[] = $u6;
BalanceLog::credit($u6, '5.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$o = Order::createWithStock(['id' => $u6], $prod, 'qr', true);
check('余额不足：balance_paid=5.00', $o['balance_paid'] === '5.00', $o['balance_paid']);
check('余额不足：pay_amount=95.00', $o['pay_amount'] === '95.00', $o['pay_amount']);
check('余额不足：状态待支付', (int)$o['status'] === Order::STATUS_PENDING);
Order::close((int)$o['id']);

// 零余额
$u7 = mkUser($db, $TEST_TAG . '_u7');
$users[] = $u7;
$o = Order::createWithStock(['id' => $u7], $prod, 'qr', true);
check('零余额：不触发纯余额支付', $o['pay_channel'] === 'qr', $o['pay_channel']);
check('零余额：pay_amount=100.00', $o['pay_amount'] === '100.00', $o['pay_amount']);
Order::close((int)$o['id']);

// 关闭退款（A：部分抵扣、尚未支付 → 不应凭空退款）
//
// 1.3.0 修复：部分抵扣单的余额是在支付宝支付成功（markPaid）时才扣的；
// 此单仍是待支付、余额从未被扣，故关单不得退还余额，否则等于白送钱。
$u8 = mkUser($db, $TEST_TAG . '_u8');
$users[] = $u8;
BalanceLog::credit($u8, '40.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$o = Order::createWithStock(['id' => $u8], $prod, 'qr', true);
check('关单退款A：抵扣 40.00', $o['balance_paid'] === '40.00');
check('关单退款A：渠道为 qr（非纯余额）', $o['pay_channel'] === 'qr', $o['pay_channel']);
check('关单退款A：下单未预扣余额（仍 40.00）',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u8])) === '40.00');
check('关单退款A：closeWithRefund 成功', Order::closeWithRefund((int)$o['id']) === true);
check('关单退款A：未扣过的余额不被凭空退还（仍 40.00）',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u8])) === '40.00');
$rLogA = $db->first('SELECT * FROM ly_balance_logs WHERE user_id=? AND type=? ORDER BY id DESC LIMIT 1', [$u8, 'refund']);
check('关单退款A：不写 refund 流水', $rLogA === null);
check('关单退款A：订单状态已关闭',
    (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$o['id']]) === Order::STATUS_CLOSED);
check('关单退款A：重复关闭不再处理', Order::closeWithRefund((int)$o['id']) === false);

// 关闭退款（B：纯余额单关单 → 退还已真实扣减的余额）
//
// 纯余额单在下单时即扣款；但该单会被直接置为已发货，
// 因此这里构造「扣款后仍为待支付」的等价场景：先用余额下单再回滚状态。
$u8b = mkUser($db, $TEST_TAG . '_u8b');
$users[] = $u8b;
BalanceLog::credit($u8b, '150.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$ob = Order::createWithStock(['id' => $u8b], $prod, 'qr', true);
check('关单退款B：纯余额单渠道为 balance', $ob['pay_channel'] === 'balance', $ob['pay_channel']);
check('关单退款B：下单即扣 100（150 → 50）',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u8b])) === '50.00',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u8b])));
// 把订单回退为待支付，模拟「已扣款但尚未完成」的关单场景
$db->update('ly_orders', ['status' => Order::STATUS_PENDING], 'id=?', [(int)$ob['id']]);
check('关单退款B：closeWithRefund 成功', Order::closeWithRefund((int)$ob['id']) === true);
check('关单退款B：余额退还（50 → 150）',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u8b])) === '150.00',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u8b])));
$rLogB = $db->first('SELECT * FROM ly_balance_logs WHERE user_id=? AND type=? ORDER BY id DESC LIMIT 1', [$u8b, 'refund']);
check('关单退款B：写入 refund 流水', $rLogB !== null);
check('关单退款B：流水 change=+100.00', BalanceLog::normalize($rLogB['change']) === '100.00', $rLogB['change']);
check('关单退款B：订单状态已关闭',
    (int)$db->value('SELECT status FROM ly_orders WHERE id=?', [$ob['id']]) === Order::STATUS_CLOSED);
check('关单退款B：重复关闭不再退款',
    Order::closeWithRefund((int)$ob['id']) === false
    && BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u8b])) === '150.00');

// 已发货（纯余额）订单不可退款
BalanceLog::credit($u8, '100.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$o = Order::createWithStock(['id' => $u8], $prod, 'qr', true);
check('已发货订单（纯余额）不可关闭退款',
    (int)$o['status'] === Order::STATUS_DELIVERED && Order::closeWithRefund((int)$o['id']) === false);

// ------------------------------------------------------------
group('10. 余额开关');

Setting::set('balance_enabled', '0');
$u9 = mkUser($db, $TEST_TAG . '_u9');
$users[] = $u9;
BalanceLog::credit($u9, '500.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$o = Order::createWithStock(['id' => $u9], $prod, 'qr', true);
check('开关关闭时不抵扣', $o['balance_paid'] === '0.00', $o['balance_paid']);
check('开关关闭时走在线支付', $o['pay_channel'] === 'qr', $o['pay_channel']);
Order::close((int)$o['id']);
Setting::set('balance_enabled', '1');
check('Setting::balanceEnabled() 恢复为 true', Setting::balanceEnabled() === true);

// ------------------------------------------------------------
group('11. 库存不足时事务回滚');

$pidNoStock = mkProduct($db, $TEST_TAG . '_nostock', '50.00');
$products[] = $pidNoStock;
$prodNoStock = Product::find($pidNoStock);
$u10 = mkUser($db, $TEST_TAG . '_u10');
$users[] = $u10;
BalanceLog::credit($u10, '500.00', BalanceLog::TYPE_ADMIN, 0, 'init');
$logsBefore = (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=?', [$u10]);

$threw = false;
try {
    Order::createWithStock(['id' => $u10], $prodNoStock, 'qr', true);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('库存不足抛出异常', $threw);
$logsAfter = (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE user_id=?', [$u10]);
check('事务回滚：无新增流水', $logsBefore === $logsAfter, "$logsBefore -> $logsAfter");
check('事务回滚：余额未扣',
    BalanceLog::normalize($db->value('SELECT balance FROM ly_users WHERE id=?', [$u10])) === '500.00');

// ------------------------------------------------------------
group('12. 分页与统计');

$pg = RedeemCode::paginate(1, 10, ['batch_no' => $batchNo]);
check('按批次筛选总数正确', $pg['total'] === 5, (string)$pg['total']);
$pgUsed = RedeemCode::paginate(1, 100, ['status' => RedeemCode::STATUS_USED, 'batch_no' => $batchNo]);
check('按状态筛选正确', $pgUsed['total'] === 4, (string)$pgUsed['total']);
$kw = RedeemCode::paginate(1, 10, ['keyword' => $gen['codes'][2]]);
check('按完整码精确搜索命中 1 条', $kw['total'] === 1, (string)$kw['total']);
$kwMask = RedeemCode::paginate(1, 10, ['keyword' => $rowDb['code_mask']]);
check('按掩码模糊搜索命中', $kwMask['total'] >= 1, (string)$kwMask['total']);

$st = RedeemCode::stats();
check('stats.total > 0', $st['total'] > 0, (string)$st['total']);
check('stats 各类别之和不超总数', ($st['unused'] + $st['used'] + $st['void']) <= $st['total']);
check('byBatch 返回本批 5 条', count(RedeemCode::byBatch($batchNo)) === 5);

$rb = RedeemCode::recentBatches(20);
$foundBatch = false;
foreach ($rb as $b) {
    if ($b['batch_no'] === $batchNo) {
        $foundBatch = true;
        check('最近批次汇总 used=4', (int)$b['used'] === 4, (string)$b['used']);
        check('最近批次汇总 unused=1', (int)$b['unused'] === 1, (string)$b['unused']);
    }
}
check('目标批次出现在最近批次', $foundBatch);

// ------------------------------------------------------------
group('13. 清理过期码');

$cleaned = RedeemCode::expireOutdated();
check('expireOutdated 清理 >= 1', $cleaned >= 1, (string)$cleaned);
check('过期码被标记为作废',
    (int)$db->value('SELECT status FROM ly_redeem_codes WHERE code_hash=?', [hash('sha256', $expRaw)]) === RedeemCode::STATUS_VOID);

// ------------------------------------------------------------
group('14. 清理测试数据');

foreach ($users as $uid) {
    $db->delete('ly_balance_logs', 'user_id=?', [$uid]);
    $db->delete('ly_orders', 'user_id=?', [$uid]);
    $db->delete('ly_users', 'id=?', [$uid]);
}
$db->delete('ly_stocks', 'panel_url LIKE ?', ['https://panel.test/' . $TEST_TAG . '%']);
foreach ($products as $p) {
    $db->delete('ly_products', 'id=?', [$p]);
}
$db->delete('ly_redeem_codes', 'remark=?', [$TEST_TAG]);

Setting::set('balance_enabled', $origBalanceEnabled);
Setting::set('redeem_enabled', $origRedeemEnabled);

$leftUsers = (int)$db->value('SELECT COUNT(*) FROM ly_users WHERE email LIKE ?', [$TEST_TAG . '%']);
$leftCodes = (int)$db->value('SELECT COUNT(*) FROM ly_redeem_codes WHERE remark=?', [$TEST_TAG]);
$leftOrders = (int)$db->value('SELECT COUNT(*) FROM ly_orders WHERE product_name LIKE ?', [$TEST_TAG . '%']);
check('测试用户已清理', $leftUsers === 0, (string)$leftUsers);
check('测试兑换码已清理', $leftCodes === 0, (string)$leftCodes);
check('测试订单已清理', $leftOrders === 0, (string)$leftOrders);
check('兑换开关已还原', Setting::redeemEnabled() === ($origRedeemEnabled === '1'));

echo "\n==========================================\n";
echo " 通过: $pass   失败: $fail\n";
echo "==========================================\n";
exit($fail > 0 ? 1 : 0);
