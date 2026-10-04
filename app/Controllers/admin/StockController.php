<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Product;
use App\Models\Stock;

/**
 * 后台库存管理（宝塔面板信息）
 */
class StockController extends BaseController
{
    public function listing(): void
    {
        Auth::requireAdmin();
        $page    = max(1, (int)input('page', 1));
        $filter  = [
            'product_id' => (int)input('product_id', 0),
            'status'     => input('status', ''),
            'keyword'    => (string)input('keyword'),
        ];
        $result  = Stock::paginate($page, 20, $filter);
        $products = \App\Database::instance()->select('SELECT id,name FROM ly_products ORDER BY sort DESC, id DESC');

        $total = Stock::stats();

        $this->adminView('admin/stock_list', [
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $page,
            'perPage'  => 20,
            'filter'   => $filter,
            'products' => $products,
            'stats'    => $total,
            'title'    => '库存管理',
        ]);
    }

    /** 批量导入页面 */
    public function import(): void
    {
        Auth::requireAdmin();
        $products = \App\Database::instance()->select('SELECT id,name,stock_mode FROM ly_products ORDER BY sort DESC, id DESC');

        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            Auth::verifyCsrf();
            $productId = (int)input('product_id');
            $text      = (string)($_POST['content'] ?? '');

            if ($productId <= 0 || !Product::find($productId)) {
                flash_set('danger', '请选择要导入的商品');
                redirect(url('admin/stock/import'));
            }
            if (trim($text) === '') {
                flash_set('danger', '导入内容不能为空');
                redirect(url('admin/stock/import'));
            }

            $r = Stock::import($productId, $text);
            log_write('admin_stock_import', sprintf('导入库存 %d 条，失败 %d 条', $r['ok'], $r['fail']), [
                'product_id' => $productId,
            ]);

            if ($r['ok'] > 0) {
                flash_set('success', '成功导入 ' . $r['ok'] . ' 条面板信息');
            }
            if ($r['fail'] > 0) {
                foreach (array_slice($r['errors'], 0, 8) as $err) {
                    flash_set('warning', $err);
                }
            }

            redirect(url('admin/stock/list', ['product_id' => $productId]));
        }

        $this->adminView('admin/stock_import', [
            'products' => $products,
            'title'    => '批量导入库存',
        ]);
    }

    public function form(): void
    {
        Auth::requireAdmin();
        $id = (int)input('id');
        $stock = $id > 0 ? Stock::find($id) : null;
        $products = \App\Database::instance()->select('SELECT id,name FROM ly_products ORDER BY sort DESC, id DESC');

        $this->adminView('admin/stock_form', [
            'stock'    => $stock,
            'products' => $products,
            'title'    => $stock ? '编辑面板信息' : '添加面板信息',
        ]);
    }

    public function save(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        $data = [
            'product_id' => (int)input('product_id'),
            'panel_url'  => mb_substr((string)input('panel_url'), 0, 250),
            'panel_user' => mb_substr((string)input('panel_user'), 0, 110),
            'panel_pass' => mb_substr((string)input('panel_pass'), 0, 110),
            'remark'     => mb_substr((string)input('remark'), 0, 250),
        ];

        if ($data['product_id'] <= 0 || $data['panel_url'] === '' || $data['panel_user'] === '' || $data['panel_pass'] === '') {
            flash_set('danger', '商品、面板链接、账号、密码均为必填项');
            redirect(url('admin/stock/form', $id > 0 ? ['id' => $id] : []));
        }

        if ($id > 0) {
            Stock::update($id, $data);
            flash_set('success', '面板信息已更新');
        } else {
            $data['status'] = 0;
            Stock::create($data);
            flash_set('success', '面板信息已添加');
        }

        redirect(url('admin/stock/list', ['product_id' => $data['product_id']]));
    }

    public function delete(): void
    {
        Auth::requireAdmin();
        $this->requirePost();
        $id = (int)input('id');
        $s = Stock::find($id);
        if (!$s) {
            flash_set('danger', '记录不存在');
            redirect(url('admin/stock/list'));
        }
        if ((int)$s['status'] === 1) {
            flash_set('warning', '该面板信息已售出，为保留交易凭证不可删除');
            redirect(url('admin/stock/list', ['product_id' => (int)$s['product_id']]));
        }
        Stock::delete($id);
        flash_set('success', '已删除');
        redirect(url('admin/stock/list', ['product_id' => (int)$s['product_id']]));
    }

    public function batchDelete(): void
    {
        Auth::requireAdmin();
        $this->requirePost();
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (!$ids) {
            flash_set('warning', '请先勾选要删除的记录');
            redirect(url('admin/stock/list'));
        }
        $db = \App\Database::instance();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = $db->query('DELETE FROM ly_stocks WHERE status=0 AND id IN (' . $in . ')', array_values($ids))->rowCount();
        log_write('admin_stock_batch_delete', '批量删除库存 ' . $n . ' 条');
        flash_set('success', '已删除 ' . $n . ' 条未售库存');
        redirect(url('admin/stock/list'));
    }
}
