<?php

namespace App\Payment;

use App\Models\Setting;

/**
 * 支付宝网关（纯 PHP 实现官方 OpenAPI 协议）
 *
 * 支持：
 *   - 当面付（扫码支付）  alipay.trade.precreate
 *   - 电脑网站支付        alipay.trade.page.pay
 *   - 交易查询            alipay.trade.query
 *   - 交易关闭            alipay.trade.close
 *
 * 不使用官方 SDK / Composer，签名为自实现 RSA2。
 */
class AlipayGateway
{
    public const GATEWAY_PROD    = 'https://openapi.alipay.com/gateway.do';
    public const GATEWAY_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    private array $config;

    public function __construct(array $config = null)
    {
        $this->config = $config ?? Setting::alipay();
    }

    public function gatewayUrl(): string
    {
        if ($this->config['gateway'] === 'prod' || $this->config['gateway'] === 'production') {
            return self::GATEWAY_PROD;
        }
        return self::GATEWAY_SANDBOX;
    }

    /**
     * 组装公共请求参数
     */
    private function commonParams(string $method, array $bizContent, string $notifyUrl = '', string $returnUrl = ''): array
    {
        $params = [
            'app_id'      => $this->config['app_id'],
            'method'      => $method,
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
        ];

        if ($notifyUrl !== '') {
            $params['notify_url'] = $notifyUrl;
        }
        if ($returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }
        return $params;
    }

    /**
     * 发起 API 请求（POST 到网关）
     *
     * @return array 解析后的响应内容
     * @throws \RuntimeException
     */
    public function execute(string $method, array $bizContent, string $notifyUrl = '', string $returnUrl = ''): array
    {
        $this->assertConfigured();

        $params = $this->commonParams($method, $bizContent, $notifyUrl, $returnUrl);
        $params['sign'] = AlipaySign::sign($params, $this->config['private_key']);

        $response = $this->httpPost($this->gatewayUrl(), $params);
        $nodeName = str_replace('.', '_', $method) . '_response';
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new \RuntimeException('支付宝返回数据解析失败：' . mb_substr($response, 0, 300));
        }

        // 兼容 err_response
        if (isset($data['error_response'])) {
            $err = $data['error_response'];
            throw new \RuntimeException(sprintf(
                '支付宝接口错误 [%s] %s（%s）',
                $err['code'] ?? '',
                $err['msg'] ?? '',
                $err['sub_msg'] ?? ''
            ));
        }

        if (!isset($data[$nodeName])) {
            throw new \RuntimeException('支付宝返回结构异常：' . mb_substr($response, 0, 300));
        }

        $result = $data[$nodeName];

        // 校验响应签名
        if (isset($data['sign'])) {
            $verifyParams = [
                'sign'      => $data['sign'],
                'sign_type' => 'RSA2',
            ];
            $verifyParams[$nodeName] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // 官方响应的签名字符串直接为原始 json 文本，这里用原始文本更严谨
            $rawNode = $this->extractRawNode($response, $nodeName);
            if ($rawNode !== null) {
                $verifyParams[$nodeName] = $rawNode;
            }
            try {
                if (!AlipaySign::verify($verifyParams, $this->config['public_key'])) {
                    throw new \RuntimeException('支付宝响应验签失败，请检查支付宝公钥是否正确');
                }
            } catch (\RuntimeException $e) {
                if (strpos($e->getMessage(), '验签失败') !== false) {
                    throw $e;
                }
                // 公钥未配置等情况不阻断，仅记录
                log_write('alipay_verify_warn', $e->getMessage());
            }
        }

        if (isset($result['code']) && (string)$result['code'] !== '10000') {
            throw new \RuntimeException(sprintf(
                '支付宝业务失败 [%s] %s（%s）',
                $result['code'],
                $result['msg'] ?? '',
                $result['sub_msg'] ?? ''
            ));
        }

        return $result;
    }

    /** 从原始 JSON 文本中截取指定节点原文（用于验签） */
    private function extractRawNode(string $json, string $node): ?string
    {
        $needle = '"' . $node . '":';
        $pos = strpos($json, $needle);
        if ($pos === false) {
            return null;
        }
        $start = strpos($json, '{', $pos);
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $len = strlen($json);
        $inStr = false;
        $esc = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $json[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($json, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    //  业务接口
    // ------------------------------------------------------------------

    /**
     * 当面付 - 预下单（扫码支付）
     *
     * @return array ['qr_code' => 'https://qr.alipay.com/xxx', 'out_trade_no' => ...]
     */
    public function precreate(string $outTradeNo, string $subject, string $amount, string $notifyUrl = ''): array
    {
        $biz = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => $amount,
            'subject'      => $subject,
            'timeout_express' => '30m',
        ];
        if (!empty($this->config['app_id'])) {
            // 部分场景需要指定 seller / store，这里可选
        }
        $r = $this->execute('alipay.trade.precreate', $biz, $notifyUrl);
        if (empty($r['qr_code'])) {
            throw new \RuntimeException('支付宝未返回二维码内容，请检查当面付产品是否已签约');
        }
        return $r;
    }

    /**
     * 电脑网站支付 - 生成跳转地址
     */
    public function pagePayUrl(string $outTradeNo, string $subject, string $amount, string $notifyUrl, string $returnUrl): string
    {
        $this->assertConfigured();

        $biz = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => $amount,
            'subject'      => $subject,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
        ];
        $params = $this->commonParams('alipay.trade.page.pay', $biz, $notifyUrl, $returnUrl);
        $params['sign'] = AlipaySign::sign($params, $this->config['private_key']);

        // 官方要求 GET 跳转（POST 也可，这里用 GET，兼容性更好）
        return $this->gatewayUrl() . '?' . http_build_query($params);
    }

    /** 交易查询 */
    public function query(string $outTradeNo): array
    {
        return $this->execute('alipay.trade.query', ['out_trade_no' => $outTradeNo]);
    }

    /** 关闭交易 */
    public function close(string $outTradeNo): array
    {
        return $this->execute('alipay.trade.close', ['out_trade_no' => $outTradeNo]);
    }

    /**
     * 处理异步通知参数验证
     *
     * @param array $post 支付宝 POST 过来的全部参数
     * @return array ['ok'=>bool, 'msg'=>string, 'out_trade_no'=>string, 'trade_no'=>string, 'amount'=>string]
     */
    public function verifyNotify(array $post): array
    {
        try {
            $this->assertConfigured();
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }

        if (empty($post['out_trade_no'])) {
            return ['ok' => false, 'msg' => '缺少 out_trade_no'];
        }

        // 1. 验签
        try {
            if (!AlipaySign::verify($post, $this->config['public_key'])) {
                return ['ok' => false, 'msg' => '验签失败'];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '验签异常：' . $e->getMessage()];
        }

        // 2. 校验 app_id
        if (!empty($post['app_id']) && (string)$post['app_id'] !== (string)$this->config['app_id']) {
            return ['ok' => false, 'msg' => 'app_id 不匹配'];
        }

        // 3. 交易状态
        $status = $post['trade_status'] ?? '';
        if (!in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return ['ok' => false, 'msg' => '交易状态非成功：' . $status, 'pending' => true];
        }

        return [
            'ok'           => true,
            'msg'          => 'ok',
            'out_trade_no' => $post['out_trade_no'],
            'trade_no'     => $post['trade_no'] ?? '',
            'amount'       => $post['total_amount'] ?? $post['receipt_amount'] ?? '0.00',
        ];
    }

    // ------------------------------------------------------------------

    private function assertConfigured(): void
    {
        if (empty($this->config['app_id'])) {
            throw new \RuntimeException('尚未配置支付宝 APPID，请前往后台「系统设置 → 支付宝接口」填写');
        }
        if (empty($this->config['private_key'])) {
            throw new \RuntimeException('尚未配置应用私钥');
        }
        if (empty($this->config['public_key'])) {
            throw new \RuntimeException('尚未配置支付宝公钥');
        }
    }

    /**
     * 发送 POST 请求（兼容无 curl 扩展的环境）
     */
    private function httpPost(string $url, array $data): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
                CURLOPT_USERAGENT      => 'LYCloud/1.0',
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                throw new \RuntimeException('请求支付宝网关失败：' . $err);
            }
            return $resp;
        }

        // 降级：stream
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded;charset=utf-8\r\n",
                'content'       => http_build_query($data),
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new \RuntimeException('请求支付宝网关失败（stream 模式）');
        }
        return $resp;
    }
}
