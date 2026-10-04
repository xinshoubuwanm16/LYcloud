<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Order;
use App\Models\Stock;

/**
 * 后台订单管理
 */
class OrderController extends BaseController
{
    public function listing(): void
    {
        Auth::requireAdmin();
        $page   = max(1, (int)input('page', 1));
        $filter = [
            'status'  => input('status', ''),
            'keyword' => (string)input('keyword'),
        ];
        $result = Order::paginate($page, 20, $filter);

        $this->adminView('admin/order_list', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'filter'  => $filter,
            'title'   => '订单管理',
        ]);
    }

    public function detail(): void
    {
        Auth::requireAdmin();
        $id = (int)input('id');
        $order = Order::find($id);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('admin/order/list'));
        }

        $user  = \App\Models\User::find((int)$order['user_id']);
        $panel = Order::panel($id);

        // 同商品可用的未售库存（手动发货用）
        $availStocks = \App\Database::instance()->select(
            'SELECT id, panel_url, panel_user, remark FROM ly_stocks WHERE product_id=? AND status=0 ORDER BY id ASC LIMIT 50',
            [(int)$order['product_id']]
        );

        $isMnbt  = Order::isMnbtOrder($order);
        $product = \App\Models\Product::find((int)$order['product_id']);

        $this->adminView('admin/order_detail', [
            'order'       => $order,
            'user'        => $user,
            'panel'       => $panel,
            'availStocks' => $availStocks,
            'isMnbt'      => $isMnbt,
            'product'     => $product,
            'deliverText' => Order::DELIVER_STATUS_TEXT[(int)($order['deliver_status'] ?? 0)] ?? '—',
            'mnbtReady'   => \App\Models\Setting::mnbtConfigured(),
            'title'       => '订单详情',
        ]);
    }

    /**
     * 后台手动重试 MNBT 开通
     *
     * 场景：上游主管机平台短暂故障导致订单落到「待重试」，或管理员排障后确认上游已恢复。
     * 与 Cron 自动重试的区别：不受最大重试次数限制，且失败时不会自动退款
     * （避免在人工排障过程中静默把用户的钱退掉、订单关掉）。
     */
    public function retryMnbt(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $orderId = (int)input('order_id');
        $order   = Order::find($orderId);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('admin/order/list'));
        }

        $r = Order::adminRetryMnbt($orderId);
        log_write('admin_mnbt_retry', '后台手动重试 MNBT 开通 ' . $order['order_no'], [
            'order_id' => $orderId,
            'ok'       => $r['ok'],
            'msg'      => $r['msg'],
        ]);
        flash_set($r['ok'] ? 'success' : 'warning', $r['msg']);
        redirect(url('admin/order/detail', ['id' => $orderId]));
    }

    /** 后台代用户一键登录 MNBT 面板（客服排障用，同样走服务端中转不暴露密码） */
    public function mnbtLogin(): void
    {
        Auth::requireAdmin();
        $id = (int)input('id');

        $target = Order::mnbtLoginTarget($id, 0, true);
        if ($target === null) {
            flash_set('warning', '该订单暂无可用主机面板（未开通成功 / 缺少密码 / MNBT 未配置）。');
            redirect(url('admin/order/detail', ['id' => $id]));
        }

        log_write('admin_mnbt_oneclick', '管理员一键登录 MNBT 面板', [
            'order_id' => $id,
            'username' => $target['username'],
        ]);

        header('Location: ' . $target['url'], true, 302);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Referrer-Policy: no-referrer');
        exit;
    }

    /** 手动发货 / 重新分配面板信息 */
    public function deliver(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $orderId = (int)input('order_id');
        $stockId = (int)input('stock_id');

        $order = Order::find($orderId);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('admin/order/list'));
        }
        if ($stockId <= 0) {
            flash_set('danger', '请选择一条要分配的面板信息');
            redirect(url('admin/order/detail', ['id' => $orderId]));
        }

        $ok = Order::manualDeliver($orderId, $stockId);
        if ($ok) {
            log_write('admin_deliver', '手动发货订单 ' . $order['order_no'], ['stock_id' => $stockId]);
            // 手动发货同样发送发货通知邮件（失败不影响发货结果）
            \App\Mail\MailService::sendDelivery($orderId);
            flash_set('success', '发货成功，用户已可查看面板登录信息');
        } else {
            flash_set('danger', '发货失败：该库存已被其他订单占用或订单状态异常');
        }
        redirect(url('admin/order/detail', ['id' => $orderId]));
    }

    /**
     * 捐赠支付人工核验：确认收款并自动发货（1.7.9）
     *
     * 管理员核实收款账单后调用；复用 markPaid 幂等链路（自动扣余额抵扣部分、
     * 分配库存/MNBT 开通、发通知邮件）。tradeNo 使用人工核验标识，与真实回调区分。
     */
    public function confirmDonate(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $orderId = (int)input('order_id');
        $order = Order::find($orderId);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('admin/order/list'));
        }
        if ((string)$order['pay_channel'] !== 'donate' || (int)$order['status'] !== Order::STATUS_PENDING) {
            flash_set('warning', '仅「待支付」状态的捐赠订单可确认收款');
            redirect(url('admin/order/detail', ['id' => $orderId]));
        }

        $ok = Order::markPaid($orderId, 'DONATE-' . $order['order_no'], '捐赠支付人工核验');
        if ($ok) {
            log_write('admin_confirm_donate', '人工核验捐赠收款并发货 ' . $order['order_no'], [
                'pay_amount' => $order['pay_amount'],
            ]);
            flash_set('success', '已确认收款，订单已自动发货，用户已收到通知邮件');
        } else {
            flash_set('danger', '确认收款失败：订单状态异常（可能已被回调处理或已关闭），请刷新后查看');
        }
        redirect(url('admin/order/detail', ['id' => $orderId]));
    }

    /** 关闭订单 */
    public function close(): void
    {
        Auth::requireAdmin();
        $this->requirePost();
        $orderId = (int)input('order_id');

        $order = Order::find($orderId);
        if (!$order) {
            flash_set('danger', '订单不存在');
            redirect(url('admin/order/list'));
        }

        if (Order::close($orderId)) {
            log_write('admin_close_order', '关闭订单 ' . $order['order_no']);
            flash_set('success', '订单已关闭，占用的库存已释放');
        } else {
            flash_set('warning', '仅「待支付」订单可关闭');
        }
        redirect(url('admin/order/detail', ['id' => $orderId]));
    }

    /** 导出 CSV */
    public function export(): void
    {
        Auth::requireAdmin();
        $filter = ['status' => input('status', ''), 'keyword' => (string)input('keyword')];
        $rows = Order::paginate(1, 5000, $filter)['rows'];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders_' . date('YmdHis') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM，防 Excel 乱码
        fputcsv($out, ['订单号', '用户邮箱', '商品', '金额', '支付方式', '支付宝交易号', '状态', '下单时间', '支付时间']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['order_no'],
                $r['user_email'] ?? '',
                $r['product_name'],
                money($r['amount']),
                $r['pay_channel'] === 'qr' ? '当面付' : '电脑网站',
                $r['trade_no'],
                status_text((int)$r['status']),
                $r['created_at'],
                $r['paid_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}
