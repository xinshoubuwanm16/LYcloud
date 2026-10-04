<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\BalanceLog;
use App\Models\User;

/**
 * 后台用户管理
 */
class UserController extends BaseController
{
    public function listing(): void
    {
        Auth::requireAdmin();
        $page    = max(1, (int)input('page', 1));
        $keyword = (string)input('keyword');
        $result  = User::paginate($page, 20, $keyword);

        $this->adminView('admin/user_list', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'keyword' => $keyword,
            'title'   => '用户管理',
        ]);
    }

    public function toggle(): void
    {
        Auth::requireAdmin();
        $this->requirePost();
        $id = (int)input('id');
        $status = User::toggleStatus($id);
        if ($status === 0) {
            flash_set('success', '用户已禁用');
        } else {
            flash_set('success', '用户已启用');
        }
        redirect(url('admin/user/list', ['page' => (int)input('page', 1)]));
    }

    public function resetPassword(): void
    {
        Auth::requireAdmin();
        $this->requirePost();
        $id = (int)input('id');
        $new = (string)($_POST['new_password'] ?? '');

        if (strlen($new) < 6) {
            flash_set('danger', '新密码至少 6 位');
            redirect(url('admin/user/list'));
        }
        User::resetPassword($id, $new);
        log_write('admin_reset_pwd', '重置用户 #' . $id . ' 密码');
        flash_set('success', '密码已重置');
        redirect(url('admin/user/list'));
    }

    /**
     * 调整用户余额（加/减）
     *
     * 支持正负号：正数入账、负数扣减。扣减时余额不足则整体拒绝。
     * 所有变动写入 ly_balance_logs 流水，type=admin，便于审计。
     */
    public function adjustBalance(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id     = (int)input('id');
        $amount = trim((string)input('amount', ''));
        $remark = trim((string)input('remark', ''));

        $back = url('admin/user/list', ['page' => (int)input('page', 1), 'keyword' => (string)input('keyword')]);

        $user = User::find($id);
        if (!$user) {
            flash_set('danger', '用户不存在');
            redirect($back);
        }

        if ($amount === '') {
            flash_set('danger', '请输入调整金额');
            redirect($back);
        }

        // 归一化并校验金额（validateAmount 成功返回 ''，失败返回错误文案）
        $normalized = BalanceLog::normalize($amount);
        $err = BalanceLog::validateAmount($normalized, '-' . BalanceLog::MAX_AMOUNT, BalanceLog::MAX_AMOUNT);
        if ($err !== '') {
            flash_set('danger', $err);
            redirect($back);
        }

        if (bccomp($normalized, '0', BalanceLog::SCALE) === 0) {
            flash_set('danger', '调整金额不能为 0');
            redirect($back);
        }

        $isCredit = bccomp($normalized, '0', BalanceLog::SCALE) > 0;
        $abs = $isCredit ? $normalized : bcmul($normalized, '-1', BalanceLog::SCALE);
        $note = '管理员调整' . ($remark !== '' ? '：' . mb_substr($remark, 0, 60) : '');

        try {
            if ($isCredit) {
                $after = BalanceLog::credit($id, $abs, BalanceLog::TYPE_ADMIN, 0, $note);
            } else {
                $ok = BalanceLog::debit($id, $abs, BalanceLog::TYPE_ADMIN, 0, $note);
                if (!$ok) {
                    $cur = User::balanceOf($id);
                    flash_set('danger', '余额不足，当前余额 ¥' . money($cur) . '，无法扣减 ¥' . money($abs));
                    redirect($back);
                }
                $after = User::balanceOf($id);
            }
        } catch (\Throwable $e) {
            log_write('admin_balance_error', '调整余额失败：' . $e->getMessage(), ['user_id' => $id]);
            flash_set('danger', '调整失败：' . $e->getMessage());
            redirect($back);
        }

        Auth::flushUserCache();

        log_write('admin_balance', '调整用户 #' . $id . ' 余额 ' . ($isCredit ? '+' : '-') . $abs, [
            'user_id' => $id,
            'amount'  => $normalized,
            'after'   => $after,
            'remark'  => $remark,
        ]);

        flash_set('success', sprintf(
            '已%s ¥%s，用户当前余额 ¥%s',
            $isCredit ? '充值' : '扣减',
            money($abs),
            money($after)
        ));
        redirect($back);
    }
}
