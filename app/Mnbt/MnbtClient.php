<?php

namespace App\Mnbt;

use App\Models\Setting;

/**
 * MNBT（梦奈宝塔主机系统）API 客户端
 *
 * ── 协议要点（官方 API 文档 v1.7）────────────────────────────────
 *   · 全部请求为 POST，接口地址：{站点}/api/api.php?gn={操作码}
 *   · 认证靠四个「必带参数」，无签名：
 *       mn_bh   宝塔编号（宝塔列表中查看）
 *       mn_key  全局 API 密钥（系统设置 → API 设置）
 *       mn_keye 宝塔调用密钥（宝塔列表中查看）
 *       mn_vs   插件支持的 MNBT 版本号（如 16 代表 v1.6）
 *   · 返回 JSON：{"code":200,"msg":"..."}
 *       code=200 成功 / code=100 失败 / code=300 版本不匹配
 *   · 一键登录 / 注销登录 属例外：不带必带参数，且允许 GET。
 *
 * ── 操作码一览 ─────────────────────────────────────────────────
 *   cfif  测试连接          kt    开通主机        xf    续费
 *   tz    删除主机          zt    暂停主机        jc    解除暂停
 *   czmm  重置密码          —     一键登录(user/idcdl.php?gn=logine)
 *
 * ── 设计说明 ───────────────────────────────────────────────────
 *   1. 不引入任何依赖，与 AlipayGateway / YiPayGateway 保持一致的
 *      「curl 优先 + stream 降级」HTTP 实现风格。
 *   2. 网络与协议异常统一抛 RuntimeException，由调用方决定重试或退款；
 *      业务失败（code=100）同样抛异常，但消息携带上游原文便于排障。
 *   3. 超时可配（默认 10s 连接 / 15s 总超时），不拖垮支付回调。
 *   4. HTTP 3xx 一律不跟随，直接抛带重定向目标的诊断
 *      （1.7.13：上游套 Cloudflare 强制 HTTPS 时，http 地址会收到 301）。
 */
class MnbtClient
{
    /** 操作码常量 */
    public const OP_TEST      = 'cfif';   // 测试连接
    public const OP_CREATE    = 'kt';     // 开通主机
    public const OP_RENEW     = 'xf';     // 续费
    public const OP_DELETE    = 'tz';     // 删除主机
    public const OP_SUSPEND   = 'zt';     // 暂停主机
    public const OP_UNSUSPEND = 'jc';     // 解除暂停
    public const OP_RESET_PWD = 'czmm';   // 重置密码

    /** 响应状态码 */
    public const CODE_OK          = 200;
    public const CODE_FAIL        = 100;
    public const CODE_VERSION_BAD = 300;

    /** 产品类型 */
    public const TYPE_CDN  = 1;
    public const TYPE_HOST = 2;

    private array $config;

    public function __construct(array $config = null)
    {
        $this->config = $config ?? Setting::mnbt();
    }

    /**
     * 从系统设置构造客户端；配置不完整（或未开启开关）时返回 null
     *
     * 供「只读展示」等场景使用（如订单详情页拼一键登录直链）：
     * 这类场景不应因配置缺失抛异常，而是静默降级为不展示入口。
     */
    public static function fromSetting(): ?self
    {
        $client = new self();
        return $client->ready() ? $client : null;
    }

    // ------------------------------------------------------------------
    //  配置判定
    // ------------------------------------------------------------------

    /** 配置是否完整（可直接调用 API） */
    public function ready(): bool
    {
        $c = $this->config;
        return (bool)($c['switch'] ?? false)
            && trim((string)($c['api_url'] ?? '')) !== ''
            && trim((string)($c['mn_bh'] ?? '')) !== ''
            && trim((string)($c['mn_key'] ?? '')) !== ''
            && trim((string)($c['mn_keye'] ?? '')) !== '';
    }

    /** 缺少的配置项（用于后台友好提示） */
    public function missingFields(): array
    {
        $map = [
            'api_url' => '接口地址',
            'mn_bh'   => '宝塔编号',
            'mn_key'  => 'API 密钥',
            'mn_keye' => '宝塔调用密钥',
        ];
        $missing = [];
        foreach ($map as $k => $label) {
            if (trim((string)($this->config[$k] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    // ------------------------------------------------------------------
    //  业务接口
    // ------------------------------------------------------------------

    /**
     * 测试连接（gn=cfif）
     *
     * @return array{code:int,msg:string}
     */
    public function testConnection(string $probeUser = 'LYCloudTest'): array
    {
        return $this->request(self::OP_TEST, [
            'username' => $probeUser !== '' ? $probeUser : 'LYCloudTest',
        ]);
    }

    /**
     * 开通主机（gn=kt）
     *
     * @param array $spec {
     *   username: string  登录账号（同时是 FTP 账号）
     *   password: string  登录密码（同时是 FTP 密码）
     *   webdx:    int     网页空间上限 MB
     *   sqldx:    int     数据库空间上限 MB
     *   sizemax:  int     月流量上限 GB
     *   type:     int     1=CDN 2=主机
     *   ymbds:    int     最多绑定域名数
     *   dqtime:   string  到期时间 Y-m-d，'0' 表示永久
     * }
     * @return array{code:int,msg:string}
     */
    public function createHost(array $spec): array
    {
        $username = trim((string)($spec['username'] ?? ''));
        $password = (string)($spec['password'] ?? '');
        if ($username === '') {
            throw new \RuntimeException('MNBT 开通失败：主机账号为空');
        }
        if ($password === '') {
            throw new \RuntimeException('MNBT 开通失败：主机密码为空');
        }

        $type = (int)($spec['type'] ?? self::TYPE_HOST);
        if (!in_array($type, [self::TYPE_CDN, self::TYPE_HOST], true)) {
            $type = self::TYPE_HOST;
        }

        $dqtime = trim((string)($spec['dqtime'] ?? '0'));
        if ($dqtime === '') {
            $dqtime = '0';
        }

        return $this->request(self::OP_CREATE, [
            'username' => $username,
            'password' => $password,
            'webdx'    => max(0, (int)($spec['webdx'] ?? 0)),
            'sqldx'    => max(0, (int)($spec['sqldx'] ?? 0)),
            'sizemax'  => max(0, (int)($spec['sizemax'] ?? 0)),
            'type'     => $type,
            'ymbds'    => max(0, (int)($spec['ymbds'] ?? 0)),
            'dqtime'   => $dqtime,
        ]);
    }

    /**
     * 续费主机（gn=xf）
     *
     * @param string $username 要续费的主机账号
     * @param string $setdate  续费后的到期时间 Y-m-d
     */
    public function renewHost(string $username, string $setdate): array
    {
        return $this->request(self::OP_RENEW, [
            'username' => $username,
            'setdate'  => $setdate,
        ]);
    }

    /**
     * 删除主机（gn=tz）
     */
    public function deleteHost(string $username): array
    {
        return $this->request(self::OP_DELETE, ['username' => $username]);
    }

    /**
     * 暂停主机（gn=zt）
     */
    public function suspendHost(string $username): array
    {
        return $this->request(self::OP_SUSPEND, ['username' => $username]);
    }

    /**
     * 解除暂停（gn=jc）
     */
    public function unsuspendHost(string $username): array
    {
        return $this->request(self::OP_UNSUSPEND, ['username' => $username]);
    }

    /**
     * 重置主机密码（gn=czmm）
     */
    public function resetPassword(string $username, string $password): array
    {
        return $this->request(self::OP_RESET_PWD, [
            'username' => $username,
            'password' => $password,
        ]);
    }

    // ------------------------------------------------------------------
    //  一键登录（例外：不带必带参数）
    // ------------------------------------------------------------------

    /**
     * 生成免密登录控制面板的直链
     *
     * 文档明确：该接口 **不能** 携带 mn_bh/mn_key 等必带参数，
     * 因为参数会随 URL 暴露给最终用户。这里只拼 username/password。
     */
    public function loginUrl(string $username, string $password): string
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return '';
        }
        return $base . '/user/idcdl.php?' . http_build_query([
            'gn'       => 'logine',
            'username' => $username,
            'password' => $password,
        ]);
    }

    /**
     * 面板展示地址（不带账号密码，用于「打开控制面板」入口）
     * MNBT 用户面板入口为 {站点}/user/
     */
    public function panelHomeUrl(): string
    {
        $base = $this->baseUrl();
        return $base === '' ? '' : $base . '/user/';
    }

    // ------------------------------------------------------------------
    //  内部实现
    // ------------------------------------------------------------------

    /** 规范化站点根地址（去尾部斜杠；缺协议补 http://） */
    public function baseUrl(): string
    {
        $url = trim((string)($this->config['api_url'] ?? ''));
        if ($url === '') {
            return '';
        }
        // 允许用户直接粘贴到 api/api.php，这里裁掉多余路径只留站点根
        $url = preg_replace('#/api/api\.php.*$#i', '', $url);
        $url = rtrim($url, '/');
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        return $url;
    }

    /** API 端点地址 */
    public function endpoint(string $op): string
    {
        $base = $this->baseUrl();
        if ($base === '') {
            throw new \RuntimeException('MNBT 接口地址未配置');
        }
        return $base . '/api/api.php?gn=' . urlencode($op);
    }

    /**
     * 发起一次 API 调用
     *
     * @param string $op      操作码
     * @param array  $payload 业务参数（必带参数由本方法自动合并）
     * @return array{code:int,msg:string}
     * @throws \RuntimeException 配置缺失 / 网络异常 / 响应非法；code!=200 时同样抛出
     */
    public function request(string $op, array $payload = []): array
    {
        $missing = $this->missingFields();
        if ($missing) {
            throw new \RuntimeException('MNBT 配置不完整，缺少：' . implode('、', $missing));
        }

        $params = array_merge($this->requiredParams(), $payload);
        $raw = $this->post($this->endpoint($op), $params);

        $json = json_decode($raw, true);
        if (!is_array($json) || !array_key_exists('code', $json)) {
            throw new \RuntimeException(
                'MNBT 接口返回格式异常：' . mb_substr(trim(strip_tags((string)$raw)), 0, 150)
            );
        }

        $code = (int)$json['code'];
        $msg  = (string)($json['msg'] ?? '');

        if ($code === self::CODE_VERSION_BAD) {
            // 1.7.12：报错带上当前提交的 mn_vs 值与正确填写指引，
            // 让「版本不匹配」在后台测试连接与订单 deliver_error 里都能一眼定位
            $vs = trim((string)($this->config['mn_vs'] ?? ''));
            throw new \RuntimeException(
                'MNBT 版本不匹配（code=300）：MNBT 系统不接受当前 mn_vs=' . ($vs !== '' ? $vs : '（空）')
                . '，请到「系统设置 → MNBT 对接」把版本号改为与 MNBT 系统一致'
                . '（v1.6 填 16、v1.7 填 17、v1.82 填 182，直接填 1.82 亦可自动转换）'
                . ($msg !== '' ? '；上游返回：' . $msg : '')
            );
        }
        if ($code !== self::CODE_OK) {
            throw new \RuntimeException('MNBT 返回失败（code=' . $code . '）：' . ($msg ?: '未知原因'));
        }

        return ['code' => $code, 'msg' => $msg];
    }

    /** 四个必带参数 */
    private function requiredParams(): array
    {
        return [
            'mn_bh'   => (string)($this->config['mn_bh'] ?? ''),
            'mn_key'  => (string)($this->config['mn_key'] ?? ''),
            'mn_keye' => (string)($this->config['mn_keye'] ?? ''),
            'mn_vs'   => (string)($this->config['mn_vs'] ?? '16'),
        ];
    }

    /**
     * POST 表单提交（curl 优先，stream 降级）
     * 注意：MNBT 以明文参数鉴权，生产环境务必使用 HTTPS（文档安全建议）；
     *       若配置为 https 则强制校验证书，若为 http 则无法校验（用户自担）。
     */
    private function post(string $url, array $params): string
    {
        $body = http_build_query($params);
        $connectTimeout = max(3, (int)($this->config['connect_timeout'] ?? 10));
        $timeout        = max(5, (int)($this->config['timeout'] ?? 15));

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_USERAGENT      => 'LYCloud/' . (defined('LY_VERSION') ? LY_VERSION : '1.0'),
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // FOLLOWLOCATION=false 时 curl 会把重定向目标放进 CURLINFO_REDIRECT_URL（1.7.13）
            $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($resp === false) {
                throw new \RuntimeException('请求 MNBT 接口失败（网络）：' . ($err ?: '未知错误'));
            }
            // 1.7.13：3xx 重定向不跟随（POST 跟随后会转 GET 且掩盖配置错误），
            // 直接给出可行动的诊断——最常见的 301 是站点强制 HTTPS（Cloudflare 等 CDN）
            if ($code >= 300 && $code < 400) {
                if ($redirectUrl !== '' && stripos($redirectUrl, 'https://') === 0 && stripos($url, 'http://') === 0) {
                    $hint = '检测到 http → https 跳转（目标 ' . mb_substr($redirectUrl, 0, 120) . '）：'
                        . '请到「系统设置 → MNBT 对接」把接口地址改为 https:// 开头后保存重试';
                } elseif ($redirectUrl !== '') {
                    $hint = '重定向到 ' . mb_substr($redirectUrl, 0, 120) . '，请核对「接口地址」是否配置正确';
                } else {
                    $hint = '请到「系统设置 → MNBT 对接」核对接口地址（站点若强制 HTTPS 请使用 https:// 开头）';
                }
                throw new \RuntimeException('MNBT 接口返回 HTTP ' . $code . ' 重定向（未跟随）。' . $hint);
            }
            if ($code >= 400) {
                throw new \RuntimeException('MNBT 接口 HTTP ' . $code . '：' . mb_substr((string)$resp, 0, 150));
            }
            return (string)$resp;
        }

        // stream 降级
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                                 . "User-Agent: LYCloud\r\n",
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            throw new \RuntimeException('请求 MNBT 接口失败（stream 模式，可能是网络或证书问题）');
        }
        return (string)$resp;
    }
}
