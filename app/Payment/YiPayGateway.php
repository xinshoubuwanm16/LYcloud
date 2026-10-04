<?php

namespace App\Payment;

use App\Models\Setting;

/**
 * 易支付网关（标准彩虹易支付协议，纯 PHP 实现，零依赖）
 *
 * 支持：
 *   - submit.php  页面跳转收银台（GET，携带签名参数）
 *   - mapi.php    API 接口下单，返回支付二维码 / 跳转链接
 *   - api.php     订单查询（act=order，部分平台支持，失败自动容错）
 *   - 异步通知验签（MD5）
 *
 * 标准签名规则（MD5）：
 *   1. 剔除 sign / sign_type 及空值参数
 *   2. 按参数名 ASCII 升序排序
 *   3. 拼接为 k1=v1&k2=v2 形式
 *   4. 末尾直接拼接商户密钥 KEY（无分隔符）
 *   5. md5 后取小写
 *
 * 订单 pay_channel 取值：yipay_alipay / yipay_wxpay / yipay_qqpay
 * （保持与 'balance' 判断兼容：markPaid / closeWithRefund 的余额扣减守卫不受影响）
 */
class YiPayGateway
{
    /** 子通道 => 显示名 */
    public const CHANNEL_LABELS = [
        'alipay' => '支付宝',
        'wxpay'  => '微信支付',
        'qqpay'  => 'QQ 钱包',
    ];

    private array $config;

    public function __construct(array $config = null)
    {
        $this->config = $config ?? Setting::yipay();
    }

    // ------------------------------------------------------------------
    //  pay_channel 与易支付子通道的互转
    // ------------------------------------------------------------------

    /** 判断订单 pay_channel 是否属于易支付通道（yipay_alipay / yipay_wxpay ...） */
    public static function isChannel(string $payChannel): bool
    {
        return strncmp($payChannel, 'yipay_', 6) === 0;
    }

    /** 从 pay_channel 解析易支付子通道 type（非法值回落 alipay） */
    public static function typeOfChannel(string $payChannel): string
    {
        $t = substr($payChannel, 6);
        return isset(self::CHANNEL_LABELS[$t]) ? $t : 'alipay';
    }

    /** 由子通道 type 拼出 pay_channel 值 */
    public static function makeChannel(string $type): string
    {
        return 'yipay_' . (isset(self::CHANNEL_LABELS[$type]) ? $type : 'alipay');
    }

    /** 易支付通道显示名，如「易支付 - 支付宝」；非易支付通道返回空串 */
    public static function channelLabel(string $payChannel): string
    {
        if (!self::isChannel($payChannel)) {
            return '';
        }
        $t = self::typeOfChannel($payChannel);
        return '易支付 - ' . self::CHANNEL_LABELS[$t];
    }

    // ------------------------------------------------------------------
    //  签名
    // ------------------------------------------------------------------

    /**
     * 计算标准易支付 MD5 签名
     * 规则：剔除 sign/sign_type/空值/数组 → 参数名 ASCII 升序 → k=v& 拼接 → 末尾接 KEY → md5 小写
     */
    public static function sign(array $params, string $merchantKey): string
    {
        unset($params['sign'], $params['sign_type']);

        $pairs = [];
        foreach ($params as $k => $v) {
            if (is_array($v) || $v === null) {
                continue;
            }
            $v = (string)$v;
            if ($v === '') {
                continue; // 空值参数不参与签名
            }
            $pairs[] = [$k, $v];
        }
        usort($pairs, function ($a, $b) {
            return strcmp($a[0], $b[0]);
        });

        $s = '';
        foreach ($pairs as [$k, $v]) {
            $s .= ($s === '' ? '' : '&') . $k . '=' . $v;
        }
        return md5($s . $merchantKey);
    }

    /** 验证通知签名（hash_equals 防时序攻击） */
    public static function verifySign(array $params, string $merchantKey): bool
    {
        if ($merchantKey === '' || empty($params['sign'])) {
            return false;
        }
        $expected = self::sign($params, $merchantKey);
        return hash_equals($expected, strtolower((string)$params['sign']));
    }

    // ------------------------------------------------------------------
    //  业务接口
    // ------------------------------------------------------------------

    /** 商户 API 地址（自动补协议与末尾斜杠） */
    public function apiUrl(): string
    {
        $u = trim((string)$this->config['api_url']);
        if ($u === '') {
            throw new \RuntimeException('尚未配置易支付接口地址，请前往后台「系统设置 → 易支付」填写');
        }
        if (!preg_match('#^https?://#i', $u)) {
            $u = 'https://' . $u;
        }
        return rtrim($u, '/') . '/';
    }

    private function assertConfigured(): void
    {
        if (trim((string)$this->config['api_url']) === '') {
            throw new \RuntimeException('尚未配置易支付接口地址');
        }
        if (trim((string)$this->config['pid']) === '') {
            throw new \RuntimeException('尚未配置易支付商户 PID');
        }
        if (trim((string)$this->config['key']) === '') {
            throw new \RuntimeException('尚未配置易支付商户密钥 KEY');
        }
    }

    /** 下单公共参数（submit / mapi 共用） */
    private function orderParams(string $outTradeNo, string $subject, string $amount, string $type, string $notifyUrl, string $returnUrl): array
    {
        return [
            'pid'          => (string)$this->config['pid'],
            'type'         => $type,
            'out_trade_no' => $outTradeNo,
            'notify_url'   => $notifyUrl,
            'return_url'   => $returnUrl,
            'name'         => mb_substr($subject, 0, 100),
            'money'        => $amount,
            'sitename'     => Setting::get('site_name', 'LY云计算'),
        ];
    }

    /**
     * 页面跳转收银台：生成 submit.php 跳转 URL（GET 方式）
     */
    public function submitUrl(string $outTradeNo, string $subject, string $amount, string $type, string $notifyUrl, string $returnUrl): string
    {
        $this->assertConfigured();
        $params = $this->orderParams($outTradeNo, $subject, $amount, $type, $notifyUrl, $returnUrl);
        $params['sign'] = self::sign($params, (string)$this->config['key']);
        $params['sign_type'] = 'MD5';
        return $this->apiUrl() . 'submit.php?' . http_build_query($params);
    }

    /**
     * API 下单：请求 mapi.php，返回含 qrcode / payurl 的数组
     *
     * 平台成功响应形如 {"code":1,"payurl":"...","qrcode":"...","urlscheme":"..."}
     * 失败形如 {"code":-1,"msg":"..."}
     *
     * @return array 原始响应数组（至少含 code=1）
     * @throws \RuntimeException 网络失败 / 响应不可解析 / 业务失败
     */
    public function mapiPay(string $outTradeNo, string $subject, string $amount, string $type, string $notifyUrl, string $returnUrl): array
    {
        $this->assertConfigured();
        $params = $this->orderParams($outTradeNo, $subject, $amount, $type, $notifyUrl, $returnUrl);
        $params['sign'] = self::sign($params, (string)$this->config['key']);
        $params['sign_type'] = 'MD5';

        $resp = $this->httpRequest($this->apiUrl() . 'mapi.php?' . http_build_query($params));
        $data = self::decodeJsonLoose($resp);

        if (!is_array($data)) {
            throw new \RuntimeException('易支付返回数据无法解析：' . mb_substr($resp, 0, 200));
        }
        if (!isset($data['code']) || (int)$data['code'] !== 1) {
            throw new \RuntimeException('易支付下单失败：' . (string)($data['msg'] ?? mb_substr($resp, 0, 120)));
        }
        if (empty($data['qrcode']) && empty($data['payurl']) && empty($data['urlscheme'])) {
            throw new \RuntimeException('易支付未返回可用的支付方式（qrcode/payurl 均为空）');
        }
        return $data;
    }

    /**
     * 订单查询（api.php?act=order，key 明文方式，标准易支付通用）
     *
     * @return array 响应数组，常用字段：trade_no / out_trade_no / money / status / trade_status
     * @throws \RuntimeException
     */
    public function queryOrder(string $outTradeNo): array
    {
        $this->assertConfigured();
        $url = $this->apiUrl() . 'api.php?' . http_build_query([
            'act'          => 'order',
            'pid'          => (string)$this->config['pid'],
            'key'          => (string)$this->config['key'],
            'out_trade_no' => $outTradeNo,
        ]);
        $resp = $this->httpRequest($url);
        $data = self::decodeJsonLoose($resp);
        if (!is_array($data)) {
            throw new \RuntimeException('易支付查单返回无法解析：' . mb_substr($resp, 0, 200));
        }
        // code!=1 且无 status 字段时视为查询失败
        if (isset($data['code']) && (int)$data['code'] !== 1 && !array_key_exists('status', $data)) {
            throw new \RuntimeException('易支付查单失败：' . (string)($data['msg'] ?? mb_substr($resp, 0, 120)));
        }
        return $data;
    }

    /**
     * 判断查单结果是否「已支付」
     * 兼容不同平台的返回字段：trade_status=TRADE_SUCCESS 或 status=1
     */
    public static function querySaysPaid(array $r): bool
    {
        $st = (string)($r['trade_status'] ?? '');
        if (in_array($st, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return true;
        }
        if (array_key_exists('status', $r)) {
            $s = $r['status'];
            if (is_int($s)) {
                return $s === 1;
            }
            $s = (string)$s;
            if ($s === '1' || $s === 'TRADE_SUCCESS' || $s === 'TRADE_FINISHED') {
                return true;
            }
        }
        return false;
    }

    /**
     * 验证异步通知
     *
     * @param array $params 易支付回调携带的全部参数（GET，兼容 POST）
     * @return array ['ok'=>bool,'msg'=>string,'out_trade_no'=>string,'trade_no'=>string,'amount'=>string,'type'=>string]
     */
    public function verifyNotify(array $params): array
    {
        $key = (string)$this->config['key'];
        if ($key === '') {
            return ['ok' => false, 'msg' => '未配置易支付商户密钥'];
        }
        if (empty($params['out_trade_no'])) {
            return ['ok' => false, 'msg' => '缺少 out_trade_no'];
        }
        if (empty($params['money'])) {
            return ['ok' => false, 'msg' => '缺少 money'];
        }

        // 1. 验签
        // 本站为单入口路由（index.php?r=pay/yinotify），$_GET 会混入路由参数 r，
        // 而平台只对业务参数签名 —— 验签前必须剔除本站注入的路由键。
        $signParams = $params;
        unset($signParams['r']);
        if (!self::verifySign($signParams, $key)) {
            return ['ok' => false, 'msg' => '验签失败'];
        }

        // 2. 校验商户 PID（防跨商户伪造）
        if ((string)($params['pid'] ?? '') !== (string)$this->config['pid']) {
            return ['ok' => false, 'msg' => '商户 PID 不匹配'];
        }

        // 3. 交易状态（标准易支付仅在支付成功时回调并带 TRADE_SUCCESS）
        $status = (string)($params['trade_status'] ?? '');
        if ($status !== 'TRADE_SUCCESS') {
            return ['ok' => false, 'msg' => '交易状态非成功：' . $status, 'pending' => true];
        }

        return [
            'ok'           => true,
            'msg'          => 'ok',
            'out_trade_no' => (string)$params['out_trade_no'],
            'trade_no'     => (string)($params['trade_no'] ?? ''),
            'amount'       => (string)$params['money'],
            'type'         => (string)($params['type'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    //  工具
    // ------------------------------------------------------------------

    /** 宽松 JSON 解析：兼容 BOM、XML 声明、CDATA、HTML 注释等平台差异 */
    public static function decodeJsonLoose(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // 去掉 UTF-8 BOM
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        // 去掉 XML 声明 / HTML 注释 / XML 包裹（部分平台返回 XML 包裹的 JSON）
        $raw = preg_replace('/^<\?xml[^>]*\?>/i', '', $raw);
        $raw = preg_replace('/^<!--.*?-->/s', '', $raw);
        $raw = trim($raw);
        if ($raw !== '' && $raw[0] === '<') {
            // 提取根元素内文本
            if (preg_match('/^<[^>]+>(.*)<\/[^>]+>$/s', $raw, $m)) {
                $raw = trim($m[1]);
            }
        }
        // 去掉 CDATA
        if (strpos($raw, '<![CDATA[') === 0) {
            $raw = preg_replace('/^<!\[CDATA\[(.*)\]\]>$/s', '$1', $raw);
            $raw = trim($raw);
        }
        return json_decode($raw, true);
    }

    /**
     * 发送 GET 请求（curl 优先，stream 降级）
     */
    private function httpRequest(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'LYCloud/1.0',
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false) {
                throw new \RuntimeException('请求易支付接口失败：' . $err);
            }
            if ($code >= 400) {
                throw new \RuntimeException('易支付接口 HTTP ' . $code . '：' . mb_substr($resp, 0, 150));
            }
            return $resp;
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 20, 'ignore_errors' => true, 'user_agent' => 'LYCloud/1.0'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new \RuntimeException('请求易支付接口失败（stream 模式）');
        }
        return $resp;
    }
}
