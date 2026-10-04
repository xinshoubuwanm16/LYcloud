<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Stock;

/**
 * 后台仪表盘
 */
class DashboardController extends BaseController
{
    public function index(): void
    {
        order_auto_close(); // 管理员打开控制台时清理超时订单
        Auth::requireAdmin();

        $stats   = Order::stats();
        $trend   = Order::incomeTrend(7);
        $recent  = Order::paginate(1, 8)['rows'];
        $expire  = Order::expireStats(); // 到期管理提示（1.7.0）

        // 库存预警：可用库存 < 3 的上架商品
        $db = \App\Database::instance();
        $warning = $db->select(
            'SELECT p.id, p.name, p.price,
                    (SELECT COUNT(*) FROM ly_stocks s WHERE s.product_id=p.id AND s.status=0) AS avail
             FROM ly_products p WHERE p.status=1 AND p.stock_mode=1
             HAVING avail < 3 ORDER BY avail ASC LIMIT 10'
        );

        // 分类统计
        $byStatus = $db->select('SELECT status, COUNT(*) AS n FROM ly_orders GROUP BY status');

        // MNBT 开通异常提示（待重试 / 开通失败），仅在有值时展示
        $mnbtAttn = Order::mnbtAttentionCount();

        $this->adminView('admin/dashboard', [
            'stats'    => $stats,
            'trend'    => $trend,
            'recent'   => $recent,
            'warning'  => $warning,
            'byStatus' => $byStatus,
            'expire'   => $expire,
            'mnbtAttn' => $mnbtAttn,
            'title'    => '控制台',
        ]);
    }
}
