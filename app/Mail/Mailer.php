<?php

namespace App\Mail;

/**
 * 纯 PHP SMTP 邮件客户端（零依赖）
 *
 * 支持：
 *   - SSL 隐式加密（465，推荐）
 *   - STARTTLS 显式加密（587）
 *   - 无加密（25，仅内网，不推荐）
 *   - AUTH LOGIN 认证
 *   - 中文主题 RFC2047 编码、正文 base64（multipart/alternative）
 *
 * 设计原则：send() 永不向调用方抛异常，失败返回 false 并写日志，
 *          避免邮件服务故障拖垮注册、支付等主业务流程。
 */
class Mailer
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $fp = null;

    private array $cfg;
    private string $lastError = '';

    /** 宽松 TLS 验证模式（证书验证失败后自动降级重试时置 true） */
    private bool $relaxed = false;

    /** 最近一次 socket/TLS 调用产生的 PHP warning 列表（用于提取 OpenSSL 证书错误细节） */
    private array $sslWarnings = [];

    public function __construct(?array $config = null)
    {
        $this->cfg = $config ?? \App\Models\Setting::smtp();
    }

    /** 最近一次错误信息 */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * 发送邮件
     *
     * 流程：严格证书验证发送 → 若因 TLS 证书验证失败（常见于服务器缺 CA 根证书包，
     * 如宝塔环境未装 ca-certificates），自动降级为宽松验证重试一次并记录日志。
     *
     * @param string $to       收件人邮箱
     * @param string $subject  主题（可含中文）
     * @param string $htmlBody HTML 正文
     * @param string $textBody 纯文本正文（留空则从 HTML 粗略剥离）
     * @param string $toName   收件人显示名
     * @return bool 成功 true，失败 false（不抛异常）
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = '', string $toName = ''): bool
    {
        if ($this->doSend($to, $subject, $htmlBody, $textBody, $toName, false)) {
            return true;
        }

        // 首次失败：判断是否为 TLS 证书验证类错误
        $firstError = $this->lastError;
        if (!self::isTlsVerifyError($firstError)) {
            $this->lastError = self::translateSmtpError($firstError);
            return false; // 其他错误（认证失败、拒信等），翻译常见错误码后保留
        }

        // TLS 证书验证失败：降级宽松验证重试一次
        $this->lastError = '';
        if ($this->doSend($to, $subject, $htmlBody, $textBody, $toName, true)) {
            if (function_exists('log_write')) {
                log_write('smtp_tls_relaxed', sprintf(
                    'TLS 证书验证失败已自动降级宽松验证并重发成功：%s（首次错误：%s）。建议为 PHP 配置 openssl.cafile 以恢复严格验证。',
                    $to,
                    $firstError
                ), ['subject' => $subject]);
            }
            return true;
        }

        // TLS 降级后仍失败：humanizeTlsError 已含完整指引，不再叠加 translate 提示
        $this->lastError = self::humanizeTlsError($this->lastError, $firstError);
        return false;
    }

    /** 实际执行发送；$relaxed=true 时跳过 TLS 对端证书验证 */
    private function doSend(string $to, string $subject, string $htmlBody, string $textBody, string $toName, bool $relaxed): bool
    {
        $this->relaxed = $relaxed;
        try {
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('收件人邮箱格式无效：' . $to);
            }
            $fromEmail = $this->cfg['from_email'] !== '' ? $this->cfg['from_email'] : $this->cfg['username'];
            if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('发件人邮箱未配置或格式无效');
            }

            if ($textBody === '') {
                $textBody = self::htmlToText($htmlBody);
            }

            $message = $this->buildMessage($to, $toName, $fromEmail, $subject, $htmlBody, $textBody);

            $this->connect();
            $this->handshake();
            $this->authLogin();
            $this->sendEnvelope($fromEmail, $to);
            $this->sendData($message);
            $this->quit();

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('log_write')) {
                log_write('smtp_error', sprintf(
                    '发信失败：%s - %s%s',
                    $to,
                    $e->getMessage(),
                    $relaxed ? '（宽松验证模式）' : ''
                ), ['subject' => $subject]);
            }
            return false;
        } finally {
            if (is_resource($this->fp)) {
                @fclose($this->fp);
            }
            $this->fp = null;
        }
    }

    // ==================== 连接与握手 ====================

    private function connect(): void
    {
        $host       = (string)$this->cfg['host'];
        $port       = (int)$this->cfg['port'];
        $encryption = (string)$this->cfg['encryption'];
        $timeout    = max(5, min(60, (int)$this->cfg['timeout']));

        if ($host === '' || $port <= 0) {
            throw new \RuntimeException('SMTP 服务器地址或端口未配置');
        }

        $transport = $encryption === 'ssl' ? 'ssl' : 'tcp';

        // 严格模式（默认）：校验服务器证书链与主机名，防中间人；
        // 宽松模式（$relaxed，仅当严格模式因证书验证失败后自动降级）：跳过验证保证可达性
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => !$this->relaxed,
                'verify_peer_name'  => !$this->relaxed,
                'allow_self_signed' => $this->relaxed,
                'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        // 关键：TLS 证书验证失败时 error_get_last() 只保留最后一个 warning
        // （"Unable to connect"），证书细节（certificate verify failed）会被覆盖。
        // 必须用 set_error_handler 全量收集，否则既无法判别降级、用户也看不到根因。
        $fp = $this->withSslCapture(function () use ($ctx, $transport, $host, $port, $timeout, &$errno, &$errstr) {
            return @stream_socket_client(
                $transport . '://' . $host . ':' . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
        });

        if (!$fp) {
            // 优先展示 OpenSSL 证书细节；"Unable to connect ... (Unknown error)" 属于无信息量的噪音，丢弃
            $sslDetail = self::extractSslDetail($this->sslWarnings);
            $noise  = 'Unable to connect to ' . $transport . '://' . $host . ':' . $port;
            $detail = (stripos($errstr, $noise) === 0) ? '' : $errstr;
            if ($sslDetail !== '') {
                $detail = $detail !== '' ? $sslDetail . '；' . $detail : $sslDetail;
            } elseif ((int)$errno === 0) {
                // errno=0 说明网络层未报错（refused/timeout 均有明确 errno），
                // 失败发生在 TLS 加密协商阶段 —— 最常见根因即服务器证书验证未通过
                $detail = 'TLS 加密协商失败（最常见原因：服务器证书验证未通过，如 PHP 缺少 CA 根证书包）';
            }
            throw new \RuntimeException(sprintf('连接 SMTP 服务器 %s:%d 失败：%s (%d)', $host, $port, $detail ?: '超时或被拒绝', $errno));
        }

        stream_set_timeout($fp, $timeout);
        $this->fp = $fp;

        // 连接应答 220
        $this->expect('220', '连接');
    }

    private function handshake(): void
    {
        $hostname = self::clientHostname();

        $this->command('EHLO ' . $hostname, '250', 'EHLO');

        if ((string)$this->cfg['encryption'] === 'tls') {
            $this->command('STARTTLS', '220', 'STARTTLS');
            $ok = $this->withSslCapture(function () {
                return @stream_socket_enable_crypto(
                    $this->fp,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
            });
            if ($ok !== true) {
                $detail = self::extractSslDetail($this->sslWarnings);
                throw new \RuntimeException('STARTTLS 加密握手失败' . ($detail !== ''
                    ? '：' . $detail
                    : '：TLS 加密协商失败（最常见原因：服务器证书验证未通过，或服务器不支持 STARTTLS）'));
            }
            // 加密后需重新 EHLO
            $this->command('EHLO ' . $hostname, '250', 'EHLO(TLS)');
        }
    }

    private function authLogin(): void
    {
        $user = (string)$this->cfg['username'];
        $pass = (string)$this->cfg['password'];

        if ($user === '') {
            return; // 免认证中继（内网常见）
        }

        $this->command('AUTH LOGIN', '334', 'AUTH LOGIN');
        $this->command(base64_encode($user), '334', 'AUTH 用户名');
        $this->command(base64_encode($pass), '235', 'AUTH 密码');
    }

    private function sendEnvelope(string $from, string $to): void
    {
        $this->command('MAIL FROM:<' . $from . '>', '250', 'MAIL FROM');
        $this->command('RCPT TO:<' . $to . '>', '250', 'RCPT TO');
    }

    private function sendData(string $message): void
    {
        $this->command('DATA', '354', 'DATA');

        // 正文已 base64 编码，理论上不会出现以 "." 开头的裸行；
        // 这里仍做一次 dot-stuffing 兜底（针对邮件头等未编码内容）
        $message = preg_replace('/^\./m', '..', $message);

        // 规范换行并确保以 CRLF 结束。
        // 关键：必须用 strtr 一次性多模式替换！
        // str_replace(['\r\n','\r','\n'], "\r\n", ...) 是串行替换——第 2 轮会把原 CRLF
        // 中的 \r 单独展开、第 3 轮再把 \n 展开，导致每个换行被炸成 \r\r\n\r\n，
        // QQ 等严格服务器解析报文时头区被空行提前终止 → 550 "From" header is missing or invalid
        $message = strtr($message, ["\r\n" => self::CRLF, "\r" => self::CRLF, "\n" => self::CRLF]);
        $this->write($message . self::CRLF . '.' . self::CRLF);

        $this->expect('250', '投递');
    }

    private function quit(): void
    {
        if (is_resource($this->fp)) {
            @fwrite($this->fp, 'QUIT' . self::CRLF);
            @fgets($this->fp, 1024);
        }
    }

    // ==================== 底层读写 ====================

    /** 发送命令并校验响应码前缀 */
    private function command(string $line, string $expect, string $stage): string
    {
        $this->write($line . self::CRLF);
        return $this->expect($expect, $stage);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->fp)) {
            throw new \RuntimeException('SMTP 连接已断开');
        }
        // 循环写完整缓冲：fwrite 对网络流（尤其 TLS socket）可能部分写入，
        // 残缺报文会导致服务器解析失败或等待超时
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->fp, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('写入 SMTP 数据失败');
            }
            $written += $n;
        }
    }

    /**
     * 读取 SMTP 响应（支持多行：250-xxx 续行 + 250 xxx 结束）
     * 返回完整响应文本，并校验状态码前缀
     */
    private function expect(string $code, string $stage): string
    {
        $response = $this->readResponse();

        if (!self::codeMatches($response, $code)) {
            throw new \RuntimeException(sprintf(
                'SMTP %s 阶段失败，期望 %s，实际：%s',
                $stage,
                $code,
                trim($response)
            ));
        }
        return $response;
    }

    private function readResponse(): string
    {
        $buffer = '';

        while (true) {
            if (!is_resource($this->fp)) {
                throw new \RuntimeException('SMTP 连接已断开');
            }
            $line = @fgets($this->fp, 1024);
            if ($line === false) {
                $meta = is_resource($this->fp) ? stream_get_meta_data($this->fp) : [];
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('读取 SMTP 响应超时');
                }
                throw new \RuntimeException('SMTP 服务器关闭了连接');
            }

            $buffer .= $line;

            // 多行响应："250-XXX" 继续；"250 XXX" 结束（第 4 个字符为空格）
            if (strlen($line) < 4) {
                break;
            }
            if ($line[3] === ' ') {
                break;
            }
            if (strlen($buffer) > 65536) {
                break; // 防御：异常超长响应
            }
        }

        return $buffer;
    }

    /** 判断响应首行状态码是否以期望值开头 */
    private static function codeMatches(string $response, string $expect): bool
    {
        $firstLine = strtok($response, "\r\n");
        if ($firstLine === false || strlen($firstLine) < 3) {
            return false;
        }
        return strncmp($firstLine, $expect, strlen($expect)) === 0;
    }

    // ==================== TLS 错误识别与人话翻译 ====================

    /**
     * 在「全量收集 PHP warning」的上下文中执行 $fn
     * 用于捕获被覆盖的 OpenSSL 证书验证细节（error_get_last 只留最后一条）
     */
    private function withSslCapture(callable $fn)
    {
        $this->sslWarnings = [];
        set_error_handler(function (int $no, string $str): bool {
            $this->sslWarnings[] = $str;
            return true; // 完全接管，避免 warning 泄漏到页面/日志
        }, E_ALL);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /** 从收集的 warning 中提取 SSL/证书相关细节（去掉函数名前缀） */
    private static function extractSslDetail(array $warnings): string
    {
        foreach ($warnings as $w) {
            if (stripos($w, 'SSL') === false
                && stripos($w, 'certificate') === false
                && stripos($w, 'crypto') === false) {
                continue;
            }
            $msg = preg_replace('/^(stream_socket_client|stream_socket_enable_crypto|fsockopen)\(\):\s*/', '', $w);
            if ($msg !== '') {
                return $msg;
            }
        }
        return '';
    }

    /**
     * 判断错误是否为 TLS 证书验证类失败
     * 常见诱因：服务器（尤其宝塔/CentOS 最小安装）缺少 CA 根证书包，
     * PHP 无法构建信任链 → 严格验证必然失败，与账号密码是否正确无关
     */
    private static function isTlsVerifyError(string $msg): bool
    {
        if ($msg === '') {
            return false;
        }
        $patterns = [
            'certificate verify failed',            // OpenSSL 证书链验证失败（最常见）
            'unable to get local issuer certificate', // 缺 CA 根证书包
            'self-signed',                          // 自签名证书
            'unable to get certificate CRL',
            'certificate is not trusted',
            'did not match expected CN',            // 主机名不匹配
            'certificate has expired',
            'certificate is not yet valid',
            'SSL operation failed',                 // OpenSSL 层失败（含上述多数场景）
            'error:0A000086',                       // OpenSSL 3.x: certificate verify failed
            'error:0A000412',                       // OpenSSL 3.x: 证书相关
            'SSL routines::certificate verify failed',
            'failed to enable crypto',              // TLS 协商阶段失败（errno=0 时的典型表现）
            'TLS 加密协商失败',                      // connect()/handshake() 给出的协商失败话术
        ];
        foreach ($patterns as $p) {
            if (stripos($msg, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    /** 宽松模式重试仍失败时，把根因与人话建议拼进错误信息 */
    private static function humanizeTlsError(string $second, string $first): string
    {
        $tip = '｜此错误为 TLS 证书验证类失败，且已自动尝试宽松验证仍失败。'
            . '常见原因：PHP 缺少 CA 根证书包——请在 php.ini 中为 openssl.cafile 配置证书路径'
            . '（Linux 一般为 /etc/ssl/certs/ca-certificates.crt，宝塔可在「软件商店 → PHP → 设置 → 配置文件」中添加'
            . ' openssl.cafile=/etc/ssl/certs/ca-certificates.crt 后重载 PHP）；'
            . '若服务器网络被劫持或代理拦截也可能导致。';
        return $second . $tip;
    }

    /**
     * 将常见 SMTP 错误翻译为人话，便于管理员/用户远程报障时一眼定位。
     * 只追加提示，不改动原始错误内容；无匹配时原样返回。
     */
    public static function translateSmtpError(string $err): string
    {
        if ($err === '') {
            return $err;
        }

        // 数字响应码用整词匹配，避免误命中端口号等数字
        $codes = [
            '530' => 'SMTP 服务器要求先加密（STARTTLS/SSL）再认证，请检查「加密方式」选择（163/QQ：选 SSL，端口 465）',
            '535' => '登录失败（535）：账号或「授权码」不正确，或邮箱未开启 SMTP 服务。163/QQ 邮箱需先在邮箱网页版设置中开启 SMTP 服务，并在后台填写「授权码」（不是邮箱登录密码）',
            '521' => 'SMTP 服务未开启（521）：请到邮箱网页版「设置 → POP3/SMTP/IMAP」开启 SMTP（163 需短信验证后获得授权码）',
            '550' => '被对方拒收（550）：收件邮箱不存在或对方拒绝接收',
            '552' => '对方拒信（552）：收件箱容量已满或发信量超限',
            '553' => '发件人地址与登录账号不一致（553）：163/QQ 等邮箱要求「发件人邮箱」必须与登录账号完全相同（例如用 163 邮箱发信，发件人邮箱就要填这个 163 邮箱）',
            '554' => '被反垃圾系统拒信（554，如 DT:SPM）：发信服务器 IP 或邮件内容触发了对方垃圾邮件规则，可稍后再试或更换发件邮箱',
            '571' => '被对方网关拒绝（571）：发件服务器 IP 信誉不足，对方不接受来自它的邮件',
        ];
        foreach ($codes as $code => $hint) {
            if (preg_match('/(^|[^\d.])' . $code . '([^\d.]|$)/', $err)) {
                return $err . ' —— ' . $hint;
            }
        }

        $texts = [
            'connection timed out' => '连接超时：服务器无法连到 SMTP 服务器。最常见原因是云服务器封禁了出网端口（25/465），请到云服务商控制台安全组放行；其次是主机/端口填写错误',
            'connection refused'   => '连接被拒绝：SMTP 主机或端口不正确。163 邮箱请填 smtp.163.com、端口 465、加密方式选 SSL',
            'unable to connect'    => '无法连接 SMTP 服务器：请核对服务器地址、端口与加密方式（163 邮箱标准配置：smtp.163.com + 端口 465 + SSL）',
            'authentication failed' => '认证失败：请核对账号与授权码',
            'must issue a starttls' => '服务器要求 STARTTLS：请将加密方式改为 TLS 或换端口 587/25',
            'tls'                  => 'TLS 加密协商失败：请确认 PHP 已启用 openssl 扩展（php -m | grep openssl）',
            'dns'                  => '域名解析失败：服务器地址可能填错，或服务器 DNS 异常',
        ];
        foreach ($texts as $needle => $hint) {
            if (stripos($err, $needle) !== false) {
                return $err . ' —— ' . $hint;
            }
        }

        return $err;
    }

    // ==================== 报文组装 ====================

    private function buildMessage(
        string $to,
        string $toName,
        string $fromEmail,
        string $subject,
        string $html,
        string $text
    ): string {
        $fromName = (string)$this->cfg['from_name'];
        $domain   = self::extractDomain($fromEmail);
        $boundary = '=_LY_' . bin2hex(random_bytes(12));

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . ($fromName !== ''
            ? self::encodeHeader($fromName) . ' <' . $fromEmail . '>'
            : '<' . $fromEmail . '>');
        $headers[] = 'To: ' . ($toName !== ''
            ? self::encodeHeader($toName) . ' <' . $to . '>'
            : '<' . $to . '>');
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>';
        $headers[] = 'X-Mailer: LYCloud-Mailer';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/plain; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = self::base64Wrap($text);

        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/html; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = self::base64Wrap($html);

        $parts[] = '--' . $boundary . '--';

        return implode(self::CRLF, $headers) . self::CRLF . self::CRLF . implode(self::CRLF, $parts);
    }

    /**
     * RFC 2047 编码邮件头（中文主题/显示名）
     * 关键：必须按完整 UTF-8 字符分段再 base64，按字节切会导致中文乱码
     */
    public static function encodeHeader(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // 纯 ASCII 且无特殊字符，直接返回
        if (preg_match('/^[\x20-\x7E]*$/', $text) === 1 && strpos($text, '=?') === false) {
            return $text;
        }

        $prefix = '=?UTF-8?B?';
        $suffix = '?=';
        // 单个 encoded-word 总长不超过 75 字符，留出余量取 45 字节分片
        $chunkMax = 45;

        $chunks = [];
        $buffer = '';
        $len = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $candidate = $buffer . $char;
            if (strlen($candidate) > $chunkMax && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = $char;
            } else {
                $buffer = $candidate;
            }
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        $encoded = [];
        foreach ($chunks as $chunk) {
            $encoded[] = $prefix . base64_encode($chunk) . $suffix;
        }

        // 多个 encoded-word 之间用 CRLF + 空格分隔（RFC 2047 折行规范）
        return implode(self::CRLF . ' ', $encoded);
    }

    /** base64 编码并按 76 字符折行（RFC 2045） */
    private static function base64Wrap(string $data): string
    {
        return rtrim(chunk_split(base64_encode($data), 76, self::CRLF), self::CRLF);
    }

    /** HTML 粗略转纯文本，用于 text/plain 备选部分 */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</t[dh]>#i', "\t", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private static function clientHostname(): string
    {
        $host = gethostname();
        if (!$host || !is_string($host)) {
            $host = 'localhost';
        }
        // EHLO 参数必须是合法域名/地址字面量
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            $host = 'localhost';
        }
        return $host;
    }

    private static function extractDomain(string $email): string
    {
        $at = strrpos($email, '@');
        $domain = $at === false ? 'localhost' : substr($email, $at + 1);
        return $domain !== '' ? $domain : 'localhost';
    }
}
