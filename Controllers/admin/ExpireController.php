<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Order;

/**
 * 到期管理（1.7.0）
 *
 * 商品配置了有效期后，已售出的面板按订单到期时间归集：
 *   - 7 天内到期：黄标提醒（买家已收到提醒邮件）
 *   - 已到期：红标提示管理员删机
 *   - 删机完成后管理员手动「标记已删机」，订单移出待办
 */
class ExpireController extends BaseController
{
    public function listing(): void
    {
        Auth::requireAdmin();

        $kind  = (string)input('kind', 'due');
        if (!in_array($kind, ['due', 'expired', 'disposed'], true)) {
            $kind = 'due';
        }

        $stats = Order::expireStats();

        $this->adminView('admin/expire_list', [
            'kind'  => $kind,
            'rows'  => Order::expireList($kind),
            'stats' => $stats,
            'title' => '到期管理',
        ]);
    }

    public function dispose(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        if ($id > 0 && Order::markDisposed($id)) {
            log_write('admin_expire_dispose', '订单 #' . $id . ' 已标记删机');
            flash_set('success', '订单 #' . $id . ' 已标记删机完成');
        } else {
            flash_set('warning', '标记失败：订单不存在、已不是有效状态或已标记过');
        }
        redirect(url('admin/expire/list', ['kind' => (string)input('kind', 'expired')]));
    }
}
