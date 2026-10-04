<?php

namespace App\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;

/**
 * 前台首页 / 商品
 */
class HomeController extends BaseController
{
    public function index(): void
    {
        order_auto_close(); // 顺带清理超时未支付订单
        $products = Product::active();
        $stats = [
            'users'    => \App\Models\User::count(),
            'orders'   => (int)\App\Database::instance()->value('SELECT COUNT(*) FROM ly_orders'),
            'stock'    => (int)\App\Database::instance()->value('SELECT COUNT(*) FROM ly_stocks WHERE status=0'),
            'products' => count($products),
        ];

        $this->view('home/index', [
            'products'   => $products,
            'stats'      => $stats,
            'categories' => Category::activeWithCount(),  // 仅展示有上架商品的启用分类
            'title'      => Setting::get('site_name', 'LY云计算') . ' - IPv6宝塔面板主机购买',
        ]);
    }

    public function listing(): void
    {
        order_auto_close(); // 顺带清理超时未支付订单

        // 分类筛选：0/缺省=全部；>0=指定分类（含隐藏分类——隐藏只是不展示入口，不隐藏商品）
        $catId = max(0, (int)input('category', 0));
        $cat   = $catId > 0 ? Category::find($catId) : null;
        // 传了不存在的分类 ID 时回落「全部」，避免空页
        if ($catId > 0 && !$cat) {
            $catId = 0;
        }

        $products = Product::active(0, $catId);
        $this->view('home/listing', [
            'products'   => $products,
            'categories' => Category::activeWithCount(true),  // Tab 需要全部启用分类（含空分类，点击后显示空态而不是消失）
            'currentCat' => $cat,
            'currentCatId' => $catId,
            'title'      => ($cat ? $cat['name'] . ' - ' : '') . '全部商品 - ' . Setting::get('site_name', 'LY云计算'),
        ]);
    }

    public function show(): void
    {
        $id = (int)input('id');
        $product = Product::find($id);

        if (!$product || (int)$product['status'] !== 1) {
            http_response_code(404);
            $this->view('errors/404', ['title' => '商品不存在'], 'layout/header');
            return;
        }

        $stockCount = (int)$product['stock_mode'] === 1 ? Product::stockCount($id) : 9999;
        $others = array_filter(Product::active(6), function ($p) use ($id) {
            return (int)$p['id'] !== $id;
        });

        // 所属分类（未分类/分类已删时为 null，页面回落「未分类」）
        $cat = (int)($product['category_id'] ?? 0) > 0
            ? Category::find((int)$product['category_id'])
            : null;

        $this->view('home/product', [
            'product'    => $product,
            'stockCount' => $stockCount,
            'others'     => array_slice($others, 0, 4),
            'category'   => $cat,
            // 捐赠支付（1.7.9）：就绪时下单页默认选中并在点击时弹出收款码预览
            'donateReady'   => Setting::donateReady(),
            'donateQrAli'   => Setting::donateQr('ali'),
            'donateQrWechat' => Setting::donateQr('wechat'),
            'donateDesc'    => Setting::donateDesc(),
            'title'      => $product['name'] . ' - ' . Setting::get('site_name', 'LY云计算'),
        ]);
    }
}
