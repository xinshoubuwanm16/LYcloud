<?php
/**
 * 端到端流程测试
 * 模拟：注册 -> 下单 -> 支付回调发货 -> 查看面板信息
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Stock;

$pass = 0; $fail = 0;
function check($label, $ok, $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✔ $label\n"; }
    else { $fail++; echo "  ✘ $label" . ($extra ? "  [$extra]" : "") . "\n"; }
}

echo "\n===== LY云计算 端到端流程测试 =====\n\n";

// ---------- 1. 用户注册 ----------
echo "[1] 用户注册\n";
$email = 'flowtest' . bin2hex(random_bytes(4)) . '@qq.com';
$existing = User::findByEmail($email);
check('测试邮箱未被占用', $existing === null, '邮箱已存在');
$uid = User::create($email, 'Test@123456', '测试用户');
check('用户创建成功', $uid > 0);
$u = User::find($uid);
check('密码哈希可验证', \App\Auth::verify('Test@123456', $u['password']));
check('错误密码被拒绝', !\App\Auth::verify('wrongpass', $u['password']));

// ---------- 2. 商品与库存 ----------
echo "\n[2] 商品与库存\n";
$product = Product::find(1);
check('商品 #1 存在', $product !== null);
$availBefore = Product::stockCount(1);
check('商品 #1 有可用库存', $availBefore > 0, "当前 $availBefore 条");

// ---------- 3. 下单（原子占用库存）----------
echo "\n[3] 下单（含库存行锁占用）\n";
$order = Order::createWithStock($u, $product, 'qr');
check('订单创建成功', $order !== null);
check('订单号已生成', !empty($order['order_no']));
check('订单状态=待支付', (int)$order['status'] === 0);
check('已分配库存 ID', (int)$order['stock_id'] > 0, 'stock_id=' . $order['stock_id']);
$availAfter = Product::stockCount(1);
check('可用库存减少 1', $availAfter === $availBefore - 1, "$availBefore -> $availAfter");

$stock = Stock::find((int)$order['stock_id']);
check('库存行状态=已占用', (int)$stock['status'] === 1);
check('库存行已绑定订单', (int)$stock['order_id'] === (int)$order['id']);
check('库存含面板链接', strpos($stock['panel_url'], 'http') === 0, $stock['panel_url']);
check('库存含面板账号', $stock['panel_user'] !== '');
check('库存含面板密码', $stock['panel_pass'] !== '');

// ---------- 4. 支付回调（模拟支付宝异步通知）----------
echo "\n[4] 支付回调与自动发货\n";
$ok = Order::markPaid((int)$order['id'], '2026100122001234567890', '{"test":true}');
check('markPaid 返回 true', $ok === true);
$order2 = Order::find((int)$order['id']);
check('订单状态=已发货(2)', (int)$order2['status'] === 2, 'status=' . $order2['status']);
check('已记录支付宝交易号', $order2['trade_no'] === '2026100122001234567890');
check('已记录支付时间', !empty($order2['paid_at']));
check('已记录发货时间', !empty($order2['delivered_at']));

// ---------- 5. 幂等性 ----------
echo "\n[5] 幂等性（重复通知不应重复发货）\n";
$again = Order::markPaid((int)$order['id'], '2026100122001234567890', '{}');
check('重复 markPaid 返回 false', $again === false);
$order3 = Order::find((int)$order['id']);
check('订单状态未被改动', (int)$order3['status'] === 2);
check('库存未被重复扣减', Product::stockCount(1) === $availBefore - 1);

// ---------- 6. 面板信息交付 ----------
echo "\n[6] 面板信息交付\n";
$panel = Order::panel((int)$order['id']);
check('可通过订单取到面板信息', $panel !== null);
check('面板链接正确', $panel['panel_url'] === $stock['panel_url']);
check('面板账号正确', $panel['panel_user'] === $stock['panel_user']);
check('面板密码正确', $panel['panel_pass'] === $stock['panel_pass']);

// ---------- 7. 库存耗尽 ----------
echo "\n[7] 库存耗尽保护\n";
$p3 = Product::find(3);
$avail3 = Product::stockCount(3);
$created = 0; $blocked = false;
for ($i = 0; $i < $avail3 + 1; $i++) {
    try {
        Order::createWithStock($u, $p3, 'qr');
        $created++;
    } catch (\RuntimeException $e) {
        $blocked = true;
        check('库存耗尽时抛出正确异常', strpos($e->getMessage(), '库存不足') !== false, $e->getMessage());
        break;
    }
}
check("成功创建 $avail3 笔订单后不再超卖", $created === $avail3 && $blocked, "created=$created, avail=$avail3");
check('商品 #3 可用库存归零', Product::stockCount(3) === 0, '剩余 ' . Product::stockCount(3));

// ---------- 8. 订单关闭释放库存 ----------
echo "\n[8] 关闭订单释放库存\n";
$pendings = \App\Database::instance()->select(
    'SELECT id FROM ly_orders WHERE status=0 AND product_id=3 LIMIT 1'
);
if ($pendings) {
    $pid = (int)$pendings[0]['id'];
    check('关闭前库存为 0', Product::stockCount(3) === 0);
    $closed = Order::close($pid);
    check('订单关闭成功', $closed === true);
    check('库存已释放回 1', Product::stockCount(3) === 1, '剩余 ' . Product::stockCount(3));
    $oc = Order::find($pid);
    check('订单状态=已关闭(3)', (int)$oc['status'] === 3);
} else {
    check('存在待支付订单', false, '没有找到待支付订单');
}

// ---------- 9. 无限库存商品 ----------
echo "\n[9] 无限库存商品\n";
$p4 = Product::find(4);
check('商品 #4 为无限库存模式', (int)$p4['stock_mode'] === 0);
$o4 = Order::createWithStock($u, $p4, 'page');
check('无限库存商品可下单', $o4 !== null);
check('无限库存订单不占用库存行', (int)$o4['stock_id'] === 0);

// ---------- 10. 订单查询隔离 ----------
echo "\n[10] 订单归属校验\n";
check('用户可查到自己的订单', Order::findForUser((int)$order['id'], $uid) !== null);
check('其他用户查不到该订单', Order::findForUser((int)$order['id'], 999999) === null);

// ---------- 11. 销量统计 ----------
echo "\n[11] 销量统计\n";
$p1 = Product::find(1);
check('商品 #1 销量已累加', (int)$p1['sales'] > 0, 'sales=' . $p1['sales']);

// ---------- 12. 统计汇总 ----------
echo "\n[12] 后台统计\n";
$stats = Order::stats();
check('累计销售额 > 0', $stats['income_total'] > 0, '¥' . number_format($stats['income_total'], 2));
check('已支付订单数 > 0', $stats['paid_total'] > 0);
$trend = Order::incomeTrend(7);
check('趋势数据返回 7 天', count($trend) === 7);
check('今日销售额已统计', end($trend)['amount'] > 0);

echo "\n===== 测试结果 =====\n";
echo "通过: $pass   失败: $fail\n\n";
exit($fail > 0 ? 1 : 0);
