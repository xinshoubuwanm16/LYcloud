<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Category;

/**
 * 后台商品分类管理
 *
 * 覆盖：
 *   - 分类的增删改查 / 启停 / 排序
 *   - 删除保护（分类下仍有商品时默认拒绝，可强制删除并置为未分类）
 */
class CategoryController extends BaseController
{
    /** 列表 */
    public function listing(): void
    {
        Auth::requireAdmin();

        $rows  = Category::all(false);
        $stats = Category::stats();

        // 批量补每个分类的商品数（含下架，供删除保护提示用）
        foreach ($rows as &$r) {
            $r['use_count']        = Category::useCount((int)$r['id']);
            $r['active_use_count'] = Category::activeUseCount((int)$r['id']);
        }
        unset($r);

        $this->adminView('admin/category_list', [
            'rows'  => $rows,
            'stats' => $stats,
            'title' => '商品分类',
        ]);
    }

    /** 新建 / 编辑表单 */
    public function form(): void
    {
        Auth::requireAdmin();
        $id  = (int)input('id');
        $cat = $id > 0 ? Category::find($id) : null;
        if ($id > 0 && !$cat) {
            flash_set('danger', '分类不存在');
            redirect(url('admin/category/list'));
        }

        $this->adminView('admin/category_form', [
            'cat'    => $cat,
            'icons'  => Category::ICONS,
            'useCount' => $cat ? Category::useCount((int)$cat['id']) : 0,
            'title'  => $cat ? '编辑分类' : '添加分类',
        ]);
    }

    /** 保存（新建或更新） */
    public function save(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id   = (int)input('id');
        $data = [
            'name'        => (string)input('name', ''),
            'slug'        => (string)input('slug', ''),
            'icon'        => (string)input('icon', ''),
            'color'       => (string)input('color', ''),
            'description' => (string)input('description', ''),
            'sort'        => (int)input('sort', 0),
            'status'      => (int)input('status', 1),
        ];

        $err = Category::validate($data);
        if ($err !== '') {
            flash_set('danger', $err);
            redirect(url('admin/category/form', $id > 0 ? ['id' => $id] : []));
        }

        if ($id > 0) {
            if (!Category::find($id)) {
                flash_set('danger', '分类不存在');
                redirect(url('admin/category/list'));
            }
            Category::update($id, $data);
            flash_set('success', '分类已更新');
            log_write('admin_category_save', '更新分类 #' . $id, $data);
        } else {
            $id = Category::create($data);
            flash_set('success', '分类已添加');
            log_write('admin_category_save', '新建分类 #' . $id, $data);
        }

        redirect(url('admin/category/list'));
    }

    /** 启用 / 隐藏 */
    public function toggle(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        if (!Category::find($id)) {
            $this->jsonError('分类不存在', 404);
        }
        $status = Category::toggle($id);
        flash_set('success', $status === Category::STATUS_ON ? '分类已启用' : '分类已隐藏（前台不再展示入口）');
        redirect(url('admin/category/list'));
    }

    /** 删除（默认有商品时拒绝，force=1 强制并置为未分类） */
    public function delete(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id    = (int)input('id');
        $force = (int)input('force', 0) === 1;

        $result = Category::delete($id, $force);
        if (!$result['ok']) {
            flash_set('danger', $result['msg']);
            redirect(url('admin/category/list'));
        }

        $msg = $result['affected'] > 0
            ? '分类已删除，' . $result['affected'] . ' 个商品已置为「未分类」'
            : '分类已删除';
        flash_set('success', $msg);
        log_write('admin_category_delete', '删除分类 #' . $id, ['force' => $force, 'affected' => $result['affected']]);
        redirect(url('admin/category/list'));
    }

    /** 上移 / 下移（在相邻分类间交换 sort，避免手工填数字） */
    public function move(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id  = (int)input('id');
        $dir = (string)input('dir', 'up');   // up=上移（更靠前）

        $cat = Category::find($id);
        if (!$cat) {
            $this->jsonError('分类不存在', 404);
        }

        $db = \App\Database::instance();
        // 排序口径与列表一致：sort DESC, id ASC
        if ($dir === 'up') {
            $neighbor = $db->first(
                'SELECT * FROM ly_categories
                  WHERE (sort > ? OR (sort = ? AND id < ?))
                  ORDER BY sort ASC, id DESC LIMIT 1',
                [(int)$cat['sort'], (int)$cat['sort'], $id]
            );
        } else {
            $neighbor = $db->first(
                'SELECT * FROM ly_categories
                  WHERE (sort < ? OR (sort = ? AND id > ?))
                  ORDER BY sort DESC, id ASC LIMIT 1',
                [(int)$cat['sort'], (int)$cat['sort'], $id]
            );
        }

        if (!$neighbor) {
            flash_set('warning', $dir === 'up' ? '已经在最前面了' : '已经在最后面了');
            redirect(url('admin/category/list'));
        }

        // 同 sort 值时交换 sort 无意义：改成错开 1，确保顺序真正变化
        // （自己拿 $b、邻居拿 $a，故 up 时 b 取更大值让自己变靠前）
        $a = (int)$cat['sort'];
        $b = (int)$neighbor['sort'];
        if ($a === $b) {
            if ($dir === 'up') {
                $a--; $b++;
            } else {
                $a++; $b--;
            }
        }

        $db->transaction(function () use ($db, $id, $neighbor, $a, $b) {
            $db->update('ly_categories', ['sort' => $b], 'id=?', [$id]);
            $db->update('ly_categories', ['sort' => $a], 'id=?', [(int)$neighbor['id']]);
        });

        flash_set('success', '排序已调整');
        redirect(url('admin/category/list'));
    }
}
