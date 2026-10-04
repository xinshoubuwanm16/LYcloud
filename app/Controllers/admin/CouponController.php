<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\UserCoupon;

/**
 * 后台优惠券管理
 *
 * 覆盖：
 *   - 券模板的增删改查 / 启停
 *   - 定向发放给指定用户（受「每人限次」约束）
 *   - 持券人明细
 */
class CouponController extends BaseController
{
    /** 表单字段（用于回填） */
    private const FORM_KEYS = [
        'name', 'code', 'type', 'value', 'min_amount', 'max_discount',
        'scope', 'per_user_limit', 'received_limit', 'claimable',
        'status', 'start_at', 'expires_at', 'remark',
    ];

    /** 列表 */
    public function listing(): void
    {
        Auth::requireAdmin();

        $page    = max(1, (int)input('page', 1));
        $keyword = trim((string)input('keyword', ''));
        $type    = trim((string)input('type', ''));
        $status  = input('status');
        $scope   = input('scope');

        $filter = ['keyword' => $keyword, 'type' => $type];
        if ($status !== '' && $status !== null) {
            $filter['status'] = (int)$status;
        }
        if ($scope !== '' && $scope !== null) {
            $filter['scope'] = (int)$scope;
        }

        $result = Coupon::paginate($page, 20, $filter);

        // 补充每张券的适用商品名（仅 scope=1 时才有值）
        foreach ($result['rows'] as &$row) {
            $row['scope_names'] = (int)$row['scope'] === Coupon::SCOPE_PRODUCT
                ? Coupon::scopeProductNames((int)$row['id'])
                : [];
        }
        unset($row);

        // 批量取「持有/使用情况」：列表展示 + 决定删除按钮走安全删除还是强制删除
        $usage = [];
        foreach ($result['rows'] as $row) {
            $usage[(int)$row['id']] = Coupon::usage((int)$row['id']);
        }

        $this->adminView('admin/coupon_list', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'keyword' => $keyword,
            'type'    => $type,
            'status'  => $status,
            'scope'   => $scope,
            'stats'   => Coupon::stats(),
            'usage'   => $usage,
            'title'   => '优惠券管理',
        ]);
    }

    /** 新增 / 编辑表单 */
    public function form(): void
    {
        Auth::requireAdmin();

        $id = (int)input('id', 0);
        $coupon = $id > 0 ? Coupon::find($id) : null;
        if ($id > 0 && !$coupon) {
            flash_set('danger', '优惠券不存在');
            redirect(url('admin/coupon/list'));
        }

        $this->adminView('admin/coupon_form', [
            'coupon'    => $coupon,
            'id'        => $id,
            'products'  => Coupon::productOptions(),
            'scopeIds'  => $coupon ? Coupon::scopeProductIds($id) : [],
            'title'     => $coupon ? '编辑优惠券' : '新增优惠券',
        ]);
    }

    /** 保存 */
    public function save(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id', 0);
        $this->rememberOld(self::FORM_KEYS);

        $data = [];
        foreach (self::FORM_KEYS as $k) {
            $data[$k] = input($k, '');
        }
        // 复选框/下拉未提交时给默认值
        $data['scope']     = input('scope', (string)Coupon::SCOPE_ALL);
        $data['status']    = input('status', (string)Coupon::STATUS_ON);
        $data['claimable'] = input('claimable', '0');

        $productIds = $_POST['product_ids'] ?? [];
        if (!is_array($productIds)) {
            $productIds = [];
        }

        $admin = Auth::admin();
        $result = $id > 0
            ? Coupon::update($id, $data, $productIds)
            : Coupon::create($data, $productIds, (int)$admin['id']);

        if (!$result['ok']) {
            flash_set('danger', $result['msg']);
            redirect(url('admin/coupon/form', $id > 0 ? ['id' => $id] : []));
        }

        $this->clearOld();

        if ($id > 0) {
            log_write('admin_coupon_update', '更新优惠券 #' . $id, ['admin_id' => (int)$admin['id']]);
            flash_set('success', '优惠券已保存');
        } else {
            log_write('admin_coupon_create', '创建优惠券 #' . $result['id'], ['admin_id' => (int)$admin['id']]);
            flash_set('success', '优惠券创建成功');
        }

        redirect(url('admin/coupon/list'));
    }

    /** 启用 / 停用 */
    public function toggle(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        $coupon = Coupon::find($id);
        $back = url('admin/coupon/list', [
            'page'    => (int)input('page', 1),
            'keyword' => (string)input('keyword'),
            'type'    => (string)input('type'),
            'status'  => (string)input('status'),
            'scope'   => (string)input('scope'),
        ]);

        if (!$coupon) {
            flash_set('danger', '优惠券不存在');
            redirect($back);
        }

        $target = (int)$coupon['status'] === Coupon::STATUS_ON ? Coupon::STATUS_OFF : Coupon::STATUS_ON;
        Coupon::setStatus($id, $target);
        log_write('admin_coupon_toggle', ($target === Coupon::STATUS_ON ? '启用' : '停用') . '优惠券 #' . $id);

        flash_set('success', ($target === Coupon::STATUS_ON ? '已启用' : '已停用') . '「' . $coupon['name'] . '」');
        redirect($back);
    }

    /** 删除（券已被持有/使用时需输入确认口令走强制删除） */
    public function delete(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        $back = url('admin/coupon/list', [
            'page'    => (int)input('page', 1),
            'keyword' => (string)input('keyword'),
            'type'    => (string)input('type'),
            'status'  => (string)input('status'),
            'scope'   => (string)input('scope'),
        ]);

        $force = (int)input('force', 0) === 1;
        if ($force) {
            // 二次确认：必须输入口令，避免误删
            $confirm = trim((string)input('confirm'));
            if ($confirm !== Coupon::FORCE_CONFIRM_WORD) {
                flash_set('danger', '强制删除口令不正确，请输入「' . Coupon::FORCE_CONFIRM_WORD . '」后重试');
                redirect($back);
            }
        }

        $result = Coupon::delete($id, $force);
        if (!$result['ok']) {
            flash_set('danger', $result['msg']);
            redirect($back);
        }

        flash_set('success', $result['msg']);
        redirect($back);
    }

    /** 定向发放页 */
    public function grant(): void
    {
        Auth::requireAdmin();

        $id = (int)input('id');
        $coupon = Coupon::find($id);
        if (!$coupon) {
            flash_set('danger', '优惠券不存在');
            redirect(url('admin/coupon/list'));
        }

        $this->adminView('admin/coupon_grant', [
            'coupon'      => $coupon,
            'userOptions' => $this->userOptions(),
            'maxGrant'    => UserCoupon::MAX_GRANT,
            'title'       => '发放优惠券 - ' . $coupon['name'],
        ]);
    }

    /** 执行发放 */
    public function doGrant(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        $back = url('admin/coupon/grant', ['id' => $id]);

        $coupon = Coupon::find($id);
        if (!$coupon) {
            flash_set('danger', '优惠券不存在');
            redirect(url('admin/coupon/list'));
        }

        // 支持两种选人方式：勾选 user_ids[] 或手填 emails
        $userIds = $_POST['user_ids'] ?? [];
        if (!is_array($userIds)) {
            $userIds = [];
        }
        $userIds = array_map('intval', $userIds);

        $emailsRaw = trim((string)input('emails', ''));
        if ($emailsRaw !== '') {
            foreach (preg_split('/[\s,，;；]+/u', $emailsRaw, -1, PREG_SPLIT_NO_EMPTY) as $email) {
                $u = \App\Models\User::findByEmail(strtolower(trim($email)));
                if ($u) {
                    $userIds[] = (int)$u['id'];
                }
            }
        }

        if (!$userIds) {
            flash_set('danger', '请至少选择一位用户，或填写有效的用户邮箱');
            redirect($back);
        }

        $result = UserCoupon::grant($id, $userIds);
        log_write('admin_coupon_grant', sprintf(
            '向 %d 人发放优惠券 #%d（成功 %d / 跳过 %d）',
            count($userIds),
            $id,
            $result['granted'],
            $result['skipped']
        ));

        flash_set($result['ok'] ? 'success' : 'warning', $result['msg']);
        redirect($result['ok'] ? url('admin/coupon/list') : $back);
    }

    /** 持券人明细 */
    public function holders(): void
    {
        Auth::requireAdmin();

        $id = (int)input('id');
        $coupon = Coupon::find($id);
        if (!$coupon) {
            flash_set('danger', '优惠券不存在');
            redirect(url('admin/coupon/list'));
        }

        $status = input('status');
        $page   = max(1, (int)input('page', 1));
        $result = UserCoupon::holders($id, $page, 20);

        // 状态为「已失效」时把过期未用的也算进来（与前台口径一致）
        $rows = $result['rows'];
        if ($status !== '' && $status !== null) {
            $want = (int)$status;
            $rows = array_values(array_filter($rows, function ($r) use ($want) {
                $st = (int)$r['status'];
                if ($want === UserCoupon::STATUS_EXPIRED) {
                    return $st === UserCoupon::STATUS_EXPIRED
                        || ($st === UserCoupon::STATUS_UNUSED
                            && !empty($r['expires_at'])
                            && strtotime((string)$r['expires_at']) < time());
                }
                return $st === $want;
            }));
        }

        $this->adminView('admin/coupon_holders', [
            'coupon'  => $coupon,
            'rows'    => $rows,
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'status'  => $status,
            'title'   => '持券人 - ' . $coupon['name'],
        ]);
    }

    /**
     * 可选用户列表（下拉多选数据源）
     * 只取最近 500 个正常用户，避免用户量过大拖慢页面
     *
     * @return array
     */
    private function userOptions(): array
    {
        return \App\Database::instance()->select(
            'SELECT id,email,nickname,status FROM ly_users ORDER BY id DESC LIMIT 500'
        );
    }
}
