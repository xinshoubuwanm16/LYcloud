<?php

namespace App\Controllers\admin;

use App\Auth;
use App\Controllers\BaseController;
use App\Models\Setting;
use App\Payment\AlipayGateway;
use App\Payment\AlipaySign;
use App\Payment\YiPayGateway;

/**
 * 后台系统设置（含支付宝接口 / 易支付配置）
 */
class SettingController extends BaseController
{
    private const ALLOWED_KEYS = [
        'site_name', 'site_subtitle', 'site_keywords', 'site_description',
        'site_icp', 'site_contact_qq', 'site_contact_tg', 'site_notice', 'site_announce',
        'order_expire_min',
        'alipay_mode', 'alipay_app_id', 'alipay_private_key', 'alipay_public_key',
        'alipay_gateway', 'alipay_notify_url', 'alipay_return_url',
        'demo_pay_enabled',
        'yipay_switch', 'yipay_api_url', 'yipay_pid', 'yipay_key',
        'yipay_mode', 'yipay_channels', 'yipay_notify_url', 'yipay_return_url',
        'donate_desc',
        'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption',
        'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name', 'smtp_timeout',
        'register_email_domains', 'register_email_verify',
        'mnbt_switch', 'mnbt_api_url', 'mnbt_bh', 'mnbt_key', 'mnbt_keye', 'mnbt_vs',
        'mnbt_timeout', 'mnbt_connect_timeout', 'mnbt_max_retry', 'mnbt_default_prefix',
    ];

    /** 表单开关字段按「表单归属」处理：mail 组只管 SMTP，reg 组只管注册验证码。
     *  两个表单相互独立（都在「邮件设置」页签下），保存任何一个时另一个表单的
     *  开关不会出现在 POST 里，绝不能用"未提交则补 0"越界改写对方 ——
     *  否则就会出现"开启注册邮箱验证码后邮件服务被自动关闭"的事故。 */
    private const GROUP_SWITCHES = [
        'mail' => ['smtp_enabled'],
        'reg'  => ['register_email_verify', 'captcha_enabled'],
        'mnbt' => ['mnbt_switch'],
        'donate' => ['donate_enabled'],
    ];

    /** 留空表示「不修改」的密钥类字段（按表单分组） */
    private const SECRET_FIELDS = [
        'mail' => ['smtp_password'],
        'yipay' => ['yipay_key'],
        'mnbt' => ['mnbt_key', 'mnbt_keye'],
    ];

    public function index(): void
    {
        Auth::requireAdmin();

        $this->adminView('admin/setting', [
            'settings'      => Setting::all(),
            'alipayReady'   => Setting::alipayConfigured(),
            'yipayReady'    => Setting::yipayEnabled(),
            'smtpReady'     => Setting::smtpConfigured(),
            'mnbtReady'     => Setting::mnbtConfigured(),
            'donateReady'   => Setting::donateReady(),
            'suggestNotify' => base_url('index.php?r=pay/notify'),
            'suggestReturn' => base_url('index.php?r=pay/return'),
            'yipaySuggestNotify' => base_url('index.php?r=pay/yinotify'),
            'yipaySuggestReturn' => base_url('index.php?r=pay/yireturn'),
            'title'         => '系统设置',
        ]);
    }

    public function save(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $group = (string)input('group', 'all');
        $data  = [];

        foreach (self::ALLOWED_KEYS as $k) {
            if (!array_key_exists($k, $_POST)) {
                continue;
            }
            $v = $_POST[$k];
            $v = is_string($v) ? trim($v) : $v;

            // 密钥类字段：去掉 PEM 头尾与空白，避免粘贴格式问题
            if (in_array($k, ['alipay_private_key', 'alipay_public_key'], true)) {
                $v = str_replace(["\r", "\n", ' '], '', $v);
            }
            $data[$k] = $v;
        }

        // 开关型字段：仅处理「当前提交表单」自己的开关，未勾选时补 0
        $groupSwitches = self::GROUP_SWITCHES[$group] ?? [];
        foreach ($groupSwitches as $k) {
            $data[$k] = array_key_exists($k, $_POST) ? (string)$_POST[$k] : '0';
            $data[$k] = $data[$k] === '1' ? '1' : '0';
        }

        if ($group === 'mail') {
            // SMTP 密码留空表示"不修改"，避免误清空已保存的授权码
            if (array_key_exists('smtp_password', $data) && $data['smtp_password'] === '') {
                unset($data['smtp_password']);
            }
        } elseif ($group === 'reg') {
            // 开启验证码但邮件服务不可用：明确提醒，避免误以为已生效
            if ($data['register_email_verify'] === '1' && !Setting::emailVerifyEnforced()) {
                flash_set('warning', '注册邮箱验证码已开启，但「邮件服务」尚未开启或配置不完整，'
                    . '验证码暂时不会实际发送。请前往「邮件设置」完成 SMTP 配置并开启邮件服务。');
            }
        }

        // ---------- 密钥类字段：留空 = 不修改 ----------
        foreach ((self::SECRET_FIELDS[$group] ?? []) as $sk) {
            if (array_key_exists($sk, $data) && $data[$sk] === '') {
                unset($data[$sk]);
            }
        }

        // 校验支付宝密钥可用性
        if (!empty($data['alipay_private_key'])) {
            try {
                AlipaySign::formatPrivateKey($data['alipay_private_key']);
                $res = openssl_pkey_get_private(AlipaySign::formatPrivateKey($data['alipay_private_key']));
                if ($res === false) {
                    throw new \RuntimeException('无法解析');
                }
            } catch (\Throwable $e) {
                flash_set('danger', '应用私钥格式无效，请检查后重新粘贴');
                redirect(url('admin/setting/index', ['tab' => 'alipay']));
            }
        }
        if (!empty($data['alipay_public_key'])) {
            try {
                $res = openssl_pkey_get_public(AlipaySign::formatPublicKey($data['alipay_public_key']));
                if ($res === false) {
                    throw new \RuntimeException('无法解析');
                }
            } catch (\Throwable $e) {
                flash_set('danger', '支付宝公钥格式无效，请检查后重新粘贴');
                redirect(url('admin/setting/index', ['tab' => 'alipay']));
            }
        }

        if (isset($data['alipay_mode']) && !in_array($data['alipay_mode'], ['qr', 'page'], true)) {
            $data['alipay_mode'] = 'qr';
        }
        if (isset($data['alipay_gateway']) && !in_array($data['alipay_gateway'], ['sandbox', 'prod'], true)) {
            $data['alipay_gateway'] = 'sandbox';
        }

        // ---------- 易支付字段规范化 ----------
        if (isset($data['yipay_mode']) && !in_array($data['yipay_mode'], ['jump', 'api'], true)) {
            $data['yipay_mode'] = 'jump';
        }
        if (isset($data['yipay_channels'])) {
            // checkbox 多选提交为数组；兼容逗号串
            $raw = is_array($data['yipay_channels'])
                ? implode(',', $data['yipay_channels'])
                : (string)$data['yipay_channels'];
            $all = ['alipay', 'wxpay', 'qqpay'];
            $picked = [];
            foreach (explode(',', $raw) as $c) {
                $c = strtolower(trim($c));
                if (in_array($c, $all, true) && !in_array($c, $picked, true)) {
                    $picked[] = $c;
                }
            }
            $data['yipay_channels'] = implode(',', $picked);
        }
        if (isset($data['yipay_api_url'])) {
            $u = trim((string)$data['yipay_api_url']);
            if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                $u = 'https://' . $u;
            }
            $data['yipay_api_url'] = $u;
        }
        // 商户密钥留空表示"不修改"，避免误清空已保存的 KEY
        if ($group === 'yipay' && array_key_exists('yipay_key', $data) && $data['yipay_key'] === '') {
            unset($data['yipay_key']);
        }
        if (isset($data['yipay_switch']) && !in_array($data['yipay_switch'], ['0', '1'], true)) {
            $data['yipay_switch'] = '0';
        }

        // ---------- SMTP 字段校验 ----------
        if (isset($data['smtp_encryption']) && !in_array($data['smtp_encryption'], ['ssl', 'tls', 'none'], true)) {
            $data['smtp_encryption'] = 'ssl';
        }
        if (isset($data['smtp_port'])) {
            $p = (int)$data['smtp_port'];
            $data['smtp_port'] = ($p > 0 && $p < 65536) ? (string)$p : '465';
        }
        if (isset($data['smtp_timeout'])) {
            $t = (int)$data['smtp_timeout'];
            $data['smtp_timeout'] = ($t >= 5 && $t <= 60) ? (string)$t : '15';
        }
        if (isset($data['smtp_from_email']) && $data['smtp_from_email'] !== ''
            && filter_var($data['smtp_from_email'], FILTER_VALIDATE_EMAIL) === false) {
            flash_set('danger', '发件人邮箱格式不正确');
            redirect(url('admin/setting/index', ['tab' => 'mail']));
        }
        if (isset($data['register_email_domains'])) {
            $data['register_email_domains'] = implode(',', Setting::normalizeDomains((string)$data['register_email_domains']));
        }

        // ---------- MNBT 字段规范化 ----------
        if (isset($data['mnbt_api_url'])) {
            $u = trim((string)$data['mnbt_api_url']);
            // 允许用户直接粘贴到 api/api.php，统一裁到站点根，避免拼出双路径
            if ($u !== '') {
                $u = preg_replace('#/api/api\.php.*$#i', '', $u);
                $u = rtrim($u, '/');
                if (!preg_match('#^https?://#i', $u)) {
                    $u = 'http://' . $u;
                }
            }
            $data['mnbt_api_url'] = $u;
        }
        if (isset($data['mnbt_vs'])) {
            $vs = preg_replace('/\D/', '', (string)$data['mnbt_vs']);
            $data['mnbt_vs'] = $vs === '' ? '16' : $vs;
        }
        if (isset($data['mnbt_timeout'])) {
            $t = (int)$data['mnbt_timeout'];
            $data['mnbt_timeout'] = (string)(($t >= 5 && $t <= 60) ? $t : 15);
        }
        if (isset($data['mnbt_connect_timeout'])) {
            $t = (int)$data['mnbt_connect_timeout'];
            $data['mnbt_connect_timeout'] = (string)(($t >= 3 && $t <= 30) ? $t : 10);
        }
        if (isset($data['mnbt_max_retry'])) {
            $t = (int)$data['mnbt_max_retry'];
            $data['mnbt_max_retry'] = (string)(($t >= 0 && $t <= 10) ? $t : 2);
        }
        if (isset($data['mnbt_default_prefix'])) {
            // 前缀仅允许字母/数字/下划线，且必须以字母开头（MNBT 账号首字符为字母更稳妥）
            $pfx = preg_replace('/[^A-Za-z0-9_]/', '', (string)$data['mnbt_default_prefix']);
            $pfx = ltrim($pfx, '0123456789_');
            $data['mnbt_default_prefix'] = mb_substr($pfx !== '' ? $pfx : 'ly', 0, 12);
        }
        if ($group === 'mnbt' && isset($data['mnbt_switch']) && $data['mnbt_switch'] === '1') {
            // 开启但关键项缺失：明确提醒，避免商品配好了却开不出来
            if (!$this->mnbtFieldsComplete($data)) {
                flash_set('warning', 'MNBT 已开启，但接口地址 / 宝塔编号 / API 密钥 / 宝塔调用密钥 尚未填写完整，'
                    . '此时商品无法自动开通主机。请补齐后点击「测试连接」确认可用。');
            }
        }

        Setting::setMany($data);
        log_write('admin_setting_save', '更新系统设置（' . $group . '）', array_keys($data));
        flash_set('success', '设置已保存');

        $tabs = ['alipay', 'yipay', 'donate', 'site', 'mail', 'mnbt'];
        $tab  = in_array($group, $tabs, true) ? $group : 'alipay';
        if ($group === 'reg') { $tab = 'mail'; } // 注册限制表单位于「邮件设置」页签
        redirect(url('admin/setting/index', ['tab' => $tab]));
    }

    /**
     * 上传捐赠收款码（1.7.9）
     *
     * which=ali（支付宝）/ wechat（微信）；图片落 uploads/donate/，
     * 路径写 setting（donate_qr_ali_path / donate_qr_wechat_path），旧图自动清理。
     */
    public function uploadDonate(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $which = (string)input('which');
        if (!in_array($which, ['ali', 'wechat'], true)) {
            flash_set('danger', '参数错误');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }

        $field  = $which === 'wechat' ? 'qr_wechat' : 'qr_ali';
        $label  = $which === 'wechat' ? '微信' : '支付宝';
        $file   = $_FILES[$field] ?? null;

        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash_set('danger', '请选择要上传的' . $label . '收款码图片');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }
        if ((int)$file['size'] > 2 * 1024 * 1024) {
            flash_set('danger', '图片不能超过 2MB');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }

        $allowExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowExt, true)) {
            flash_set('danger', '仅支持 JPG / PNG / WebP 格式');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }

        // 真实图片校验（防止伪造扩展名的非图片文件落库）
        $info = @getimagesize((string)$file['tmp_name']);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            flash_set('danger', '文件不是有效的图片，请重新选择');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }
        if ((int)$info[0] < 50 || (int)$info[1] < 50) {
            flash_set('danger', '图片尺寸过小（至少 50×50），请上传完整的收款码');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }

        $dir = LY_ROOT . '/uploads/donate';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            flash_set('danger', '无法创建上传目录 uploads/donate，请检查目录权限');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }

        // 文件名完全随机，不采用用户输入；扩展名取白名单内的原始值（便于浏览器识别 MIME）
        $name = 'qr_' . $which . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            flash_set('danger', '保存图片失败，请检查 uploads/donate 目录是否可写');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }
        @chmod($dest, 0644);

        // 旧图清理 + 写配置
        $key = $which === 'wechat' ? 'donate_qr_wechat_path' : 'donate_qr_ali_path';
        $old = trim((string)Setting::get($key, ''));
        if ($old !== '' && !str_contains($old, '..') && is_file(LY_ROOT . '/' . ltrim($old, '/'))) {
            @unlink(LY_ROOT . '/' . ltrim($old, '/'));
        }
        Setting::set($key, 'uploads/donate/' . $name);

        log_write('donate_upload', '上传' . $label . '收款码 ' . $name, ['size' => (int)$file['size']]);
        flash_set('success', $label . '收款码已更新，前台即时生效');
        redirect(url('admin/setting/index', ['tab' => 'donate']));
    }

    /** 移除捐赠收款码 */
    public function removeDonate(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $which = (string)input('which');
        if (!in_array($which, ['ali', 'wechat'], true)) {
            flash_set('danger', '参数错误');
            redirect(url('admin/setting/index', ['tab' => 'donate']));
        }

        $key = $which === 'wechat' ? 'donate_qr_wechat_path' : 'donate_qr_ali_path';
        $old = trim((string)Setting::get($key, ''));
        if ($old !== '' && !str_contains($old, '..') && is_file(LY_ROOT . '/' . ltrim($old, '/'))) {
            @unlink(LY_ROOT . '/' . ltrim($old, '/'));
        }
        Setting::set($key, '');

        log_write('donate_remove', '移除收款码 ' . ($which === 'wechat' ? '微信' : '支付宝'));
        flash_set('success', '收款码已移除');
        redirect(url('admin/setting/index', ['tab' => 'donate']));
    }

    /**
     * 判断提交数据（含已保存值兜底）中 MNBT 关键项是否齐全
     * 密钥字段留空表示沿用已保存值，此时必须回读数据库判断，不能只看 POST。
     */
    private function mnbtFieldsComplete(array $data): bool
    {
        $pick = function (string $key) use ($data) {
            if (array_key_exists($key, $data) && trim((string)$data[$key]) !== '') {
                return trim((string)$data[$key]);
            }
            return trim((string)Setting::get($key, ''));
        };
        foreach (['mnbt_api_url', 'mnbt_bh', 'mnbt_key', 'mnbt_keye'] as $k) {
            if ($pick($k) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 合并「表单当前值」与「已保存配置」，得到测试连接实际使用的配置（1.7.12）
     *
     * 背景：此前「测试连接」只读已保存配置，管理员刚改完表单没点「保存配置」
     * 就点「测试连接」时，测的仍是旧配置 —— 造成「我明明填对了却测试不通过」的困惑。
     * 现在前端把表单当前值一并 POST 过来，本方法按以下规则合并：
     *   · POST 未出现的键        → 沿用已保存值（兼容旧调用方）
     *   · POST 出现但为空串      → 沿用已保存值（密钥输入框留空 = 不修改）
     *   · POST 有值              → 规范化后覆盖（api_url 裁剪、mn_vs 去非数字、超时钳制）
     *
     * @param array $saved Setting::mnbt() 结构
     * @param array $post  表单片段（mnbt_api_url/mnbt_bh/mnbt_key/mnbt_keye/mnbt_vs/mnbt_timeout/mnbt_connect_timeout）
     */
    public static function mergeMnbtFormConfig(array $saved, array $post): array
    {
        $cfg = $saved;

        // 与保存逻辑保持一致：裁掉 /api/api.php 后缀、补协议
        $normUrl = static function (string $u): string {
            $u = preg_replace('#/api/api\.php.*$#i', '', $u);
            $u = rtrim($u, '/');
            if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                $u = 'http://' . $u;
            }
            return $u;
        };
        // mn_vs：仅保留数字（1.82 → 182，与官方「15 代表 v1.5」的拼接约定一致）；无数字回落已保存值
        $normVs = static function (string $v) use ($saved): string {
            $d = preg_replace('/\D/', '', $v);
            return $d === '' ? (string)($saved['mn_vs'] ?? '16') : $d;
        };
        $clamp = static function (int $min, int $max) {
            return static function (string $v) use ($min, $max): int {
                return max($min, min($max, (int)$v));
            };
        };

        $take = static function (string $postKey, string $cfgKey, ?callable $norm = null) use ($post, &$cfg): void {
            if (!array_key_exists($postKey, $post)) {
                return; // 表单未提交该字段
            }
            $v = trim((string)$post[$postKey]);
            if ($v === '') {
                return; // 留空 = 沿用已保存值（密钥不回显场景）
            }
            $cfg[$cfgKey] = $norm ? $norm($v) : $v;
        };

        $take('mnbt_api_url', 'api_url', $normUrl);
        $take('mnbt_bh', 'mn_bh');
        $take('mnbt_key', 'mn_key');
        $take('mnbt_keye', 'mn_keye');
        $take('mnbt_vs', 'mn_vs', $normVs);
        $take('mnbt_timeout', 'timeout', $clamp(5, 60));
        $take('mnbt_connect_timeout', 'connect_timeout', $clamp(3, 30));

        return $cfg;
    }

    /**
     * 测试 MNBT 接口连通性
     * 1.7.12 起：优先使用表单当前值测试（无需先「保存配置」）；
     * 密钥输入框留空表示沿用已保存密钥。
     */
    public function testMnbt(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        $cfg = self::mergeMnbtFormConfig(Setting::mnbt(), [
            'mnbt_api_url'         => (string)input('mnbt_api_url'),
            'mnbt_bh'              => (string)input('mnbt_bh'),
            'mnbt_key'             => (string)input('mnbt_key'),
            'mnbt_keye'            => (string)input('mnbt_keye'),
            'mnbt_vs'              => (string)input('mnbt_vs'),
            'mnbt_timeout'         => (string)input('mnbt_timeout'),
            'mnbt_connect_timeout' => (string)input('mnbt_connect_timeout'),
        ]);

        $missing = [];
        foreach (['api_url' => '接口地址', 'mn_bh' => '宝塔编号', 'mn_key' => 'API 密钥', 'mn_keye' => '宝塔调用密钥'] as $k => $label) {
            if (trim((string)$cfg[$k]) === '') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            $this->jsonError('请先完整填写：' . implode('、', $missing));
        }

        try {
            $client = new \App\Mnbt\MnbtClient($cfg);
            $r = $client->testConnection();
            $this->jsonOk(['msg' => '连接成功：' . ($r['msg'] ?: 'MNBT 接口可用，密钥校验通过')]);
        } catch (\Throwable $e) {
            $this->jsonError('测试失败：' . $e->getMessage());
        }
    }

    /** 测试 SMTP 发信 */
    public function testMail(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        if (!Setting::smtpConfigured()) {
            $this->jsonError('请先完整填写 SMTP 配置（服务器、账号、授权码、发件人邮箱）并开启邮件服务');
        }

        $to = trim((string)input('to'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->jsonError('请输入有效的收件邮箱');
        }

        // 注意：这里用当前已保存的配置发信。
        // 用户若刚修改表单未保存，请先点「保存设置」再测试。
        $ok = \App\Mail\MailService::sendTest($to);

        if ($ok) {
            $this->jsonOk(['msg' => '测试邮件已发送至 ' . $to . '，请查收收件箱与垃圾箱']);
        } else {
            $this->jsonError('发送失败：' . (\App\Mail\MailService::lastError() ?: '请检查 SMTP 配置'));
        }
    }

    /** 测试支付宝连通性 */
    public function test(): void
    {
        Auth::requireAdmin();
        $this->requirePost();

        if (!Setting::alipayConfigured()) {
            $this->jsonError('请先完整填写 APPID、应用私钥、支付宝公钥');
        }

        try {
            $gw = new AlipayGateway();
            // 用一个不存在的订单号查询，能正常返回业务错误即说明签名/网络/密钥配置正确
            try {
                $gw->query('LY_TEST_' . time());
                $this->jsonOk(['msg' => '接口连通正常（意外地查到了订单）']);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                // 签名通过但订单不存在 => 配置正确
                if (strpos($msg, 'ACQ.TRADE_NOT_EXIST') !== false
                    || strpos($msg, '交易不存在') !== false
                    || strpos($msg, '40004') !== false) {
                    $this->jsonOk(['msg' => '接口连通正常，签名校验通过（返回：交易不存在，属预期结果）']);
                }
                $this->jsonError('接口返回异常：' . $msg);
            }
        } catch (\Throwable $e) {
            $this->jsonError('请求失败：' . $e->getMessage());
        }
    }
}
