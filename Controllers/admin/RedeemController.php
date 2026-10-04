<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\BalanceLog;
use App\Models\RedeemCode;
use App\Models\Setting;

/**
 * 后台兑换码管理
 *
 * 安全约定：数据库只存 sha256 哈希与掩码，明文仅在「生成成功」页面展示一次。
 * 因此生成接口用 POST-Redirect-GET 把明文暂存到 session，一次性取出后立即清除。
 */
class RedeemController extends BaseController
{
    /** 生成结果在 session 中的暂存键 */
    private const FLASH_KEY = '_redeem_generated';

    /**
     * 兑换码列表
     */
    public function listing(): void
    {
        Auth::requireAdmin();

        $page    = max(1, (int)input('page', 1));
        $status  = input('status');
        $batchNo = trim((string)input('batch_no', ''));
        $keyword = trim((string)input('keyword', ''));

        $filter = [];
        if ($status !== '' && $status !== null) {
            $filter['status'] = (int)$status;
        }
        if ($batchNo !== '') {
            $filter['batch_no'] = $batchNo;
        }
        if ($keyword !== '') {
            $filter['keyword'] = $keyword;
        }

        $result = RedeemCode::paginate($page, 20, $filter);

        $this->adminView('admin/redeem_list', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => 20,
            'status'  => $status,
            'batchNo' => $batchNo,
            'keyword' => $keyword,
            'stats'   => RedeemCode::stats(),
            'batches' => RedeemCode::recentBatches(10),
            'minAmount' => Setting::redeemMinAmount(),
            'maxAmount' => Setting::redeemMaxAmount(),
            'title'   => '兑换码管理',
        ]);
    }

    /**
     * 生成兑换码
     */
    public function generate(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $count   = (int)input('count', 0);
        $amount  = trim((string)input('amount', ''));
        $ttlDays = trim((string)input('ttl_days', ''));
        $remark  = trim((string)input('remark', ''));

        $back = url('admin/redeem/list');

        // 数量校验
        if ($count < 1 || $count > RedeemCode::MAX_BATCH) {
            flash_set('danger', '生成数量需在 1 ~ ' . RedeemCode::MAX_BATCH . ' 之间');
            redirect($back);
        }

        // 面额校验
        $amountNorm = BalanceLog::normalize($amount);
        $err = BalanceLog::validateAmount(
            $amountNorm,
            Setting::redeemMinAmount(),
            Setting::redeemMaxAmount()
        );
        if ($err !== '') {
            flash_set('danger', $err);
            redirect($back);
        }

        // 有效期：留空 = 永久有效
        $ttl = null;
        if ($ttlDays !== '') {
            $ttl = (int)$ttlDays;
            if ($ttl < 0) {
                flash_set('danger', '有效期不能为负数');
                redirect($back);
            }
            if ($ttl === 0) {
                $ttl = null; // 0 视为永久
            } elseif ($ttl > 3650) {
                flash_set('danger', '有效期最长 3650 天');
                redirect($back);
            }
        }

        $admin = Auth::admin();
        $result = RedeemCode::generate($count, $amountNorm, $ttl, (int)$admin['id'], $remark);

        if (!$result['ok']) {
            flash_set('danger', $result['msg']);
            redirect($back);
        }

        log_write('admin_redeem_generate', '生成兑换码 ' . count($result['codes']) . ' 个 / 面额 ' . $result['amount'], [
            'batch_no' => $result['batch_no'],
            'count'    => count($result['codes']),
            'amount'   => $result['amount'],
            'ttl_days' => $ttl,
        ]);

        // 明文码仅在本次响应中展示 —— 存 session 后 302，避免刷新重复生成
        $_SESSION[self::FLASH_KEY] = [
            'batch_no' => $result['batch_no'],
            'amount'   => $result['amount'],
            'codes'    => $result['codes'],
            'ttl_days' => $ttl,
            'created'  => date('Y-m-d H:i:s'),
        ];

        redirect(url('admin/redeem/generated'));
    }

    /**
     * 生成结果页（明文码展示 + 一键复制）
     */
    public function generated(): void
    {
        Auth::requireAdmin();

        $data = $_SESSION[self::FLASH_KEY] ?? null;
        // 取一次即销毁：刷新页面不会重复展示明文
        unset($_SESSION[self::FLASH_KEY]);

        $this->adminView('admin/redeem_generated', [
            'result' => $data,
            'title'  => '兑换码生成结果',
        ]);
    }

    /**
     * 作废单个兑换码
     */
    public function void(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $id = (int)input('id');
        $code = RedeemCode::find($id);

        $back = url('admin/redeem/list', [
            'page'     => (int)input('page', 1),
            'status'   => (string)input('status'),
            'batch_no' => (string)input('batch_no'),
            'keyword'  => (string)input('keyword'),
        ]);

        if (!$code) {
            flash_set('danger', '兑换码不存在');
            redirect($back);
        }

        if (RedeemCode::void($id)) {
            log_write('admin_redeem_void', '作废兑换码 #' . $id, ['code_mask' => $code['code_mask']]);
            flash_set('success', '已作废兑换码 ' . $code['code_mask']);
        } else {
            flash_set('warning', '该兑换码已被使用或已作废，无需重复操作');
        }
        redirect($back);
    }

    /**
     * 作废整批（仅未使用的）
     */
    public function voidBatch(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $batchNo = trim((string)input('batch_no', ''));
        if ($batchNo === '') {
            flash_set('danger', '批次号不能为空');
            redirect(url('admin/redeem/list'));
        }

        $n = RedeemCode::voidBatch($batchNo);
        log_write('admin_redeem_void_batch', '作废批次 ' . $batchNo . ' 共 ' . $n . ' 个');

        if ($n > 0) {
            flash_set('success', '已作废该批次 ' . $n . ' 个未使用的兑换码');
        } else {
            flash_set('warning', '该批次没有可作废的兑换码');
        }
        redirect(url('admin/redeem/list', ['batch_no' => $batchNo]));
    }

    /**
     * 清理已过期未使用的兑换码
     */
    public function cleanExpired(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $n = RedeemCode::expireOutdated();
        log_write('admin_redeem_clean', '清理过期兑换码 ' . $n . ' 个');

        if ($n > 0) {
            flash_set('success', '已清理 ' . $n . ' 个过期未使用的兑换码');
        } else {
            flash_set('info', '没有需要清理的过期兑换码');
        }
        redirect(url('admin/redeem/list'));
    }
}
