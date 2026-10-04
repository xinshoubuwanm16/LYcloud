<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Product;

/**
 * 后台商品管理
 */
class ProductController extends BaseController
{    public function listing(): void
    {
        Auth::requireAdmin();
        $page    = max(1, (int)input('page', 1));
        $keyword = (string)input('keyword');
        // 分类筛选：''=全部 / '0'=未分类 / 正整数=指定分类
        $catFilter = input('category');
        $catFilter = ($catFilter === '' || $catFilter === null) ? '' : (int)$catFilter;
        $result  = Product::paginate($page, 20, $keyword, $catFilter);

        // 批量取分类名，避免 N+1
        $catIds = [];
        foreach ($result['rows'] as $r) {
            $catIds[] = (int)$r['category_id'];
        }
        $catMap = \App\Models\Category::mapByIds($catIds);

        $this->adminView('admin/product_list', [
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'perPage'    => 20,
            'keyword'    => $keyword,
            'categories' => \App\Models\Category::all(false),
            'catMap'     => $catMap,
            'catFilter'  => $catFilter,
            'title'      => '商品管理',
        ]);
    }

    /**
     * 从表单提取 MNBT 规格字段并规范化
     *
     * 前缀仅保留字母/数字/下划线且必须以字母开头（MNBT 账号首字符为字母更稳妥）；
     * 空则回落到系统设置里的默认前缀，保证后续生成账号时一定有前缀可用。
     */
    private function mnbtSpecFromInput(): array
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string)input('mnbt_prefix'));
        $prefix = ltrim((string)$prefix, '0123456789_');
        $prefix = mb_substr($prefix, 0, 12);
        if ($prefix === '') {
            $prefix = (string)\App\Models\Setting::get('mnbt_default_prefix', 'ly');
        }

        $type = (int)input('mnbt_type', 2);
        if (!in_array($type, [1, 2], true)) {
            $type = 2;
        }

        return [
            'mnbt_prefix'  => $prefix,
            'mnbt_webdx'   => max(0, min(1048576, (int)input('mnbt_webdx', 0))),
            'mnbt_sqldx'   => max(0, min(1048576, (int)input('mnbt_sqldx', 0))),
            'mnbt_sizemax' => max(0, min(1048576, (int)input('mnbt_sizemax', 0))),
            'mnbt_type'    => $type,
            'mnbt_ymbds'   => max(0, min(9999, (int)input('mnbt_ymbds', 0))),
        ];
    }

    public function form(): void
    {
        Auth::requireAdmin();
        $id = (int)input('id');
        $product = $id > 0 ? Product::find($id) : null;
        if ($id > 0 && !$product) {
            flash_set('danger', '商品不存在');
            redirect(url('admin/product/list'));
        }

        $this->adminView('admin/product_form', [
            'product'   => $product,
            'categories' => \App\Models\Category::all(false),
            'mnbtReady' => \App\Models\Setting::mnbtConfigured(),
            'mnbtDefaultPrefix' => (string)\App\Models\Setting::get('mnbt_default_prefix', 'ly'),
            'title'     => $product ? '编辑商品' : '添加商品',
        ]);
    }

    public function save(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        $data = [
            'name'           => mb_substr((string)input('name'), 0, 140),
            'subtitle'       => mb_substr((string)input('subtitle'), 0, 240),
            'description'    => (string)($_POST['description'] ?? ''),
            'price'          => money(input('price', 0)),
            'original_price' => money(input('original_price', 0)),
            'cover'          => mb_substr((string)input('cover'), 0, 240),
            'tags'           => mb_substr((string)input('tags'), 0, 240),
            'spec'           => mb_substr((string)input('spec'), 0, 240),
            'stock_mode'     => (int)input('stock_mode', 1) === 0 ? 0 : 1,
            'auto_deliver'   => (int)input('auto_deliver', 1) === 0 ? 0 : 1,
            'deliver_mode'   => Product::normalizeDeliverMode((int)input('deliver_mode', 1)),
            'sort'           => (int)input('sort', 0),
            'sales'          => max(0, (int)input('sales', 0)),
            'status'         => (int)input('status', 1) === 0 ? 0 : 1,
        ];
        // 有效期（0 = 永久；单位白名单 day/week/month/year，非法值归一 month）
        $data += Product::normalizeDuration((int)input('duration_value', 0), (string)input('duration_unit', 'month'));
        // 每人限购次数（0 = 不限购，上限 9999）
        $data['limit_per_user'] = Product::normalizeLimit((int)input('limit_per_user', 0));
        // MNBT 规格（deliver_mode=2 时才有意义；其余模式留默认值不影响发货）
        $data += $this->mnbtSpecFromInput();

        if ($data['name'] === '') {
            flash_set('danger', '商品名称不能为空');
            redirect(url('admin/product/form', $id > 0 ? ['id' => $id] : []));
        }
        if ((float)$data['price'] < 0) {
            flash_set('danger', '售价不能为负数（填写 0 表示免费领取）');
            redirect(url('admin/product/form', $id > 0 ? ['id' => $id] : []));
        }

        // 分类归属：0=未分类；非 0 时分类必须真实存在（隐藏分类允许保留归属，但前台入口不展示）
        $catId = max(0, (int)input('category_id', 0));
        if ($catId > 0 && !\App\Models\Category::find($catId)) {
            flash_set('danger', '所选分类不存在，请重新选择');
            redirect(url('admin/product/form', $id > 0 ? ['id' => $id] : []));
        }
        $data['category_id'] = $catId;

        // MNBT 交付方式的必要校验：接口未配置时不允许直接上架，否则买家付款后开不出主机
        if ((int)$data['deliver_mode'] === Product::DELIVER_MNBT) {
            if (!\App\Models\Setting::mnbtConfigured()) {
                flash_set('danger', '该商品选择了「MNBT 实时开通」，但 MNBT 接口尚未配置或未启用。'
                    . '请先前往「系统设置 → MNBT对接」完成配置并测试连接，否则买家付款后将无法自动开通主机。');
                redirect(url('admin/product/form', $id > 0 ? ['id' => $id] : []));
            }
            if ((int)$data['mnbt_webdx'] <= 0) {
                flash_set('danger', 'MNBT 网页空间必须大于 0');
                redirect(url('admin/product/form', $id > 0 ? ['id' => $id] : []));
            }
        }

        if ($id > 0) {
            Product::update($id, $data);
            flash_set('success', '商品已更新');
        } else {
            $id = Product::create($data);
            flash_set('success', '商品已添加，请前往库存管理补充面板信息');
        }

        log_write('admin_product_save', ($id > 0 ? '保存商品 #' . $id : '新建商品'), $data);
        redirect(url('admin/product/list'));
    }

    public function toggle(): void
    {
        Auth::requireAdmin();
        $this->requirePost();
        $id = (int)input('id');
        $p = Product::find($id);
        if (!$p) {
            $this->jsonError('商品不存在', 404);
        }
        $status = (int)$p['status'] === 1 ? 0 : 1;
        Product::update($id, ['status' => $status]);
        flash_set('success', $status === 1 ? '商品已上架' : '商品已下架');
        redirect(url('admin/product/list'));
    }

    public function delete(): void
    {
        Auth::requireAdmin();
        $this->requirePost();
        $id = (int)input('id');
        Product::delete($id);
        log_write('admin_product_delete', '删除商品 #' . $id);
        flash_set('success', '商品已删除（未售库存一并清理，已售订单记录保留）');
        redirect(url('admin/product/list'));
    }
}
