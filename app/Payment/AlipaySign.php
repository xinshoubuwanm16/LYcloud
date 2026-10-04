<?php

namespace App\Payment;

/**
 * 支付宝 RSA2 签名 / 验签（纯 PHP 实现，不依赖官方 SDK）
 *
 * 签名算法：RSA2 = SHA256withRSA
 * 官方文档：https://opendocs.alipay.com/common/02kf5q
 */
class AlipaySign
{
    /**
     * 生成待签名字符串
     *
     * 规则：除 sign 外所有非空参数，按 key 字典序升序，用 & 拼接 k=v
     */
    public static function buildSignSource(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            // 数组/对象为 JSON 字符串
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = $k . '=' . $v;
        }
        return implode('&', $parts);
    }

    /**
     * 使用商户私钥签名（RSA2）
     *
     * @param array  $params 业务参数
     * @param string $privateKey 商户应用私钥（PKCS1 或 PKCS8，可含 PEM 头尾）
     * @return string base64 签名
     * @throws \RuntimeException
     */
    public static function sign(array $params, string $privateKey): string
    {
        $source = self::buildSignSource($params);
        $key = self::formatPrivateKey($privateKey);

        $res = openssl_pkey_get_private($key);
        if ($res === false) {
            throw new \RuntimeException('商户私钥格式错误，无法解析。请填写有效的 PKCS8/PKCS1 私钥。');
        }

        $signature = '';
        $ok = openssl_sign($source, $signature, $res, OPENSSL_ALGO_SHA256);
        if (is_resource($res) || $res instanceof \OpenSSLAsymmetricKey) {
            openssl_free_key($res);
        }
        if (!$ok) {
            throw new \RuntimeException('支付宝签名失败：' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    /**
     * 验证支付宝返回的签名（RSA2）
     *
     * @param array  $params 支付宝回调的全部参数（含 sign / sign_type）
     * @param string $publicKey 支付宝公钥
     */
    public static function verify(array $params, string $publicKey): bool
    {
        if (empty($params['sign'])) {
            return false;
        }
        $signType = $params['sign_type'] ?? 'RSA2';
        if (strtoupper($signType) !== 'RSA2') {
            // 本系统仅支持 RSA2，保证安全强度
            return false;
        }

        $sign = base64_decode($params['sign'], true);
        if ($sign === false) {
            return false;
        }

        $source = self::buildSignSource($params);
        $key = self::formatPublicKey($publicKey);

        $res = openssl_pkey_get_public($key);
        if ($res === false) {
            throw new \RuntimeException('支付宝公钥格式错误，无法解析。');
        }

        $ok = openssl_verify($source, $sign, $res, OPENSSL_ALGO_SHA256) === 1;
        if (is_resource($res) || $res instanceof \OpenSSLAsymmetricKey) {
            openssl_free_key($res);
        }
        return $ok;
    }

    /**
     * 格式化私钥为 PEM
     */
    public static function formatPrivateKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new \RuntimeException('商户私钥为空');
        }
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        // 纯 base64 内容，补齐换行
        $body = chunk_split(preg_replace('/\s+/', '', $key), 64, "\n");
        return "-----BEGIN RSA PRIVATE KEY-----\n" . $body . "-----END RSA PRIVATE KEY-----\n";
    }

    /**
     * 格式化公钥为 PEM
     */
    public static function formatPublicKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new \RuntimeException('支付宝公钥为空');
        }
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $body = chunk_split(preg_replace('/\s+/', '', $key), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $body . "-----END PUBLIC KEY-----\n";
    }

    /**
     * 生成一对 RSA2 密钥（供后台"一键生成密钥"使用）
     * @return array ['private'=>..., 'public'=>..., 'public_pkcs1'=>...]
     */
    public static function generateKeyPair(int $bits = 2048): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            throw new \RuntimeException('密钥生成失败：' . openssl_error_string());
        }
        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);

        // 应用公钥需要的是 PKCS8 格式（BEGIN PUBLIC KEY）
        $publicKey = $details['key'];

        // 部分场景支付宝需要 PKCS1 格式的公钥
        $publicPkcs1 = '';
        if (!empty($details['rsa']['n']) && !empty($details['rsa']['e'])) {
            $publicPkcs1 = self::toPkcs1Public($details['rsa']['n'], $details['rsa']['e']);
        }

        return [
            'private'       => $privateKey,
            'public'        => $publicKey,
            'public_pkcs1'  => $publicPkcs1,
        ];
    }

    /** 将 PKCS8 公钥转 PKCS1（用于支付宝"应用公钥"填写） */
    private static function toPkcs1Public(string $modulus, string $exponent): string
    {
        $n = self::asn1Int($modulus);
        $e = self::asn1Int($exponent);
        $rsaPublicKey = "\x30" . self::asn1Len(strlen($n) + strlen($e)) . $n . $e;
        $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03" . self::asn1Len(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = "\x30" . self::asn1Len(strlen($algId) + strlen($bitString)) . $algId . $bitString;

        // 从 SPKI 中提取 BIT STRING 内部内容 => PKCS1
        $body = chunk_split(base64_encode($rsaPublicKey), 64, "\n");
        return "-----BEGIN RSA PUBLIC KEY-----\n" . $body . "-----END RSA PUBLIC KEY-----\n";
    }

    private static function asn1Int(string $data): string
    {
        // 去掉前导 0x00，并在最高位为 1 时补 0x00
        $data = ltrim($data, "\x00");
        if ($data === '') {
            $data = "\x00";
        }
        if (ord($data[0]) > 0x7f) {
            $data = "\x00" . $data;
        }
        return "\x02" . self::asn1Len(strlen($data)) . $data;
    }

    private static function asn1Len(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $tmp = '';
        while ($len > 0) {
            $tmp = chr($len & 0xff) . $tmp;
            $len >>= 8;
        }
        return chr(0x80 | strlen($tmp)) . $tmp;
    }

    /** 格式化私钥用于显示（去掉 PEM 头尾，只留内容） */
    public static function stripKey(string $pem): string
    {
        $pem = preg_replace('/-----[^-]+-----/', '', $pem);
        return preg_replace('/\s+/', '', $pem);
    }
}
