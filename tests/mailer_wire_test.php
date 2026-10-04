<?php
/**
 * SMTP 报文线上字节级合规测试（wire-level）
 *
 * 背景：sendData() 曾用 str_replace(['\r\n','\r','\n'], "\r\n", ...) 规范化换行，
 * 串行替换把每个 CRLF 炸成 \r\r\n\r\n——mock 服务器 fgets 行处理悄悄掩盖了它，
 * 而 QQ SMTP 严格解析时头区被空行提前终止 → 550 "From" header is missing or invalid。
 * 本测试用 raw fread mock 直接抓取线上字节，杜绝行处理假象。
 *
 * 断言：无 \r\r\n、无裸 LF、From/To/Subject 头完整位于头区、
 *       线上字节与 buildMessage(+dot-stuffing) 输出全等。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Mail\Mailer;

$pass = 0; $fail = 0;
$chk = function (string $name, bool $cond, string $extra = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  ✔ $name\n"; }
    else { $fail++; echo "  ✘ $name" . ($extra !== '' ? "  -- $extra" : '') . "\n"; }
};

$port = 3900 + (getmypid() % 80);
$dump = sys_get_temp_dir() . "/wire_{$port}.bin";
$ready = sys_get_temp_dir() . "/wire_ready_{$port}.flag";
$serverFile = sys_get_temp_dir() . "/wire_mock_{$port}.php";

@unlink($dump); @unlink($ready);
file_put_contents($serverFile, <<<'PHPEOF'
<?php
$port = (int)$argv[1]; $dump = $argv[2]; $ready = $argv[3];
$srv = @stream_socket_server("tcp://127.0.0.1:$port", $en, $es, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
if (!$srv) { fwrite(STDERR, "listen failed: $es\n"); exit(1); }
file_put_contents($ready, '1');
$fp = @stream_socket_accept($srv, 30);
if (!$fp) exit(1);
fwrite($fp, "220 mock ESMTP ready\r\n");
$authStep = 0; $phase = 'cmd'; $raw = ''; $buf = '';
while (true) {
    $chunk = @fread($fp, 65536);
    if ($chunk === '' || $chunk === false) {
        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out'])) continue;
        break;
    }
    $buf .= $chunk;
    if ($phase === 'cmd') {
        while (($pos = strpos($buf, "\r\n")) !== false) {
            $line = substr($buf, 0, $pos); $buf = substr($buf, $pos + 2);
            $cmd = strtoupper(trim($line));
            if (str_starts_with($cmd, 'EHLO')) fwrite($fp, "250-mock\r\n250-AUTH LOGIN\r\n250 OK\r\n");
            elseif (str_starts_with($cmd, 'AUTH LOGIN')) { $authStep = 1; fwrite($fp, "334 VXNlcm5hbWU6\r\n"); }
            elseif ($authStep === 1) { $authStep = 2; fwrite($fp, "334 UGFzc3dvcmQ6\r\n"); }
            elseif ($authStep === 2) { $authStep = 0; fwrite($fp, "235 ok\r\n"); }
            elseif (str_starts_with($cmd, 'MAIL ') || str_starts_with($cmd, 'RCPT ')) fwrite($fp, "250 OK\r\n");
            elseif (str_starts_with($cmd, 'DATA')) { $phase = 'data'; fwrite($fp, "354 go\r\n"); break; }
            elseif (str_starts_with($cmd, 'QUIT')) { fwrite($fp, "221 bye\r\n"); break 2; }
            else fwrite($fp, "250 OK\r\n");
        }
    } else {
        $raw .= $buf; $buf = '';
        $end = strpos($raw, "\r\n.\r\n");
        if ($end !== false) {
            // 只保存报文体（不含尾部 CRLF 与 DATA 终止符），保留线上原始字节
            file_put_contents($dump, substr($raw, 0, $end));
            fwrite($fp, "250 queued OK\r\n");
            break;
        }
    }
}
PHPEOF);

$proc = proc_open(
    PHP_BINARY . ' ' . escapeshellarg($serverFile) . ' ' . $port . ' ' . escapeshellarg($dump) . ' ' . escapeshellarg($ready),
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes
);
if (!is_resource($proc)) {
    echo "SKIP: 无法启动 mock SMTP\n";
    exit(0);
}
stream_set_blocking($pipes[2], false);
for ($i = 0; $i < 40 && !file_exists($ready); $i++) usleep(50000);
if (!file_exists($ready)) {
    echo "SKIP: mock SMTP 就绪超时\n";
    proc_terminate($proc); proc_close($proc);
    exit(0);
}

try {
    // 与真实验证码邮件同构：中文显示名 + 中文主题 + 多段 HTML（触发 encoded-word 与 base64 折行）
    $html = "<html><body><h2>LY云计算</h2><p>您的验证码是 <b>123456</b>，10 分钟内有效。</p>";
    for ($i = 0; $i < 30; $i++) {
        $html .= "<tr><td>行 $i</td><td>说明文字内容示例说明文字内容示例</td></tr>\n";
    }
    $html .= "<p>.行首点测试行</p></body></html>"; // 行首点进入 text/plain 时验证 dot-stuffing

    $cfg = [
        'host' => '127.0.0.1', 'port' => $port, 'encryption' => '', 'timeout' => 8,
        'username' => 'tester@qq.com', 'password' => 'authcode',
        'from_email' => 'tester@qq.com', 'from_name' => 'LY云计算',
    ];
    $m  = new Mailer($cfg);
    $ok = $m->send('1443193454@qq.com', '【LY云计算】注册验证码', $html);
    $chk('发送成功', $ok === true, $m->lastError());

    $raw = (string)(is_file($dump) ? file_get_contents($dump) : '');
    $chk('mock 服务器收到 DATA 数据', $raw !== '');

    // 1) 线上无 \r\r（CRLF 炸裂的指纹）
    $crlfDoubled = substr_count($raw, "\r\r");
    $chk('线上无 \\r\\r（换行未被炸裂）', $crlfDoubled === 0, "发现 $crlfDoubled 处");

    // 2) 线上无裸 LF：每个 \n 的前一个字节必须是 \r
    $naked = 0; $n = strlen($raw);
    for ($i = 1; $i < $n; $i++) {
        if ($raw[$i] === "\n" && $raw[$i - 1] !== "\r") $naked++;
    }
    if ($n > 0 && $raw[0] === "\n") $naked++;
    $chk('线上无裸 LF（全部 CRLF）', $naked === 0, "发现 $naked 处");

    // 3) 头区完整：From/To/Subject 必须出现在第一个空行之前（QQ 550 的核心场景）
    $headEnd = strpos($raw, "\r\n\r\n");
    $head = $headEnd !== false ? substr($raw, 0, $headEnd) : $raw;
    $chk('头区存在', $headEnd !== false);
    $chk('From 头完整位于头区', preg_match('/^From: /m', $head) === 1);
    $chk('To 头完整位于头区', preg_match('/^To: /m', $head) === 1);
    $chk('Subject 头完整位于头区', preg_match('/^Subject: /m', $head) === 1);
    $chk('Date/MIME/Content-Type 头完整',
        preg_match('/^Date: /m', $head) === 1
        && preg_match('/^MIME-Version: 1\.0\r?$/m', $head) === 1
        && preg_match('/^Content-Type: multipart\/alternative; boundary="/m', $head) === 1);

    // 4) 线上字节与 buildMessage(+dot-stuffing) 输出全等
    //    （Message-ID / boundary / Date 均为随机或时间值，两边统一归一化后再比对）
    $normalize = function (string $s): string {
        $s = (string)preg_replace('/^Message-ID: <[0-9a-f]+@/m', 'Message-ID: <X@', $s);
        $s = (string)preg_replace('/=_LY_[0-9a-f]+/', '=_LY_X', $s); // 头部与 body 分隔行同源
        $s = (string)preg_replace('/^Date: .+$/m', 'Date: X', $s);
        return $s;
    };
    $rm = new ReflectionMethod(Mailer::class, 'buildMessage');
    $rm->setAccessible(true);
    $text = Mailer::htmlToText($html); // 与 doSend 相同的 text/plain 推导
    $expected = $rm->invoke($m, '1443193454@qq.com', '', 'tester@qq.com', '【LY云计算】注册验证码', $html, $text);
    $stuffed = preg_replace('/^\./m', '..', $expected); // sendData 的 dot-stuffing
    $chk('线上字节与理论报文全等（含 dot-stuffing）', $normalize($raw) === $normalize($stuffed),
        sprintf('线上 %d 字节 vs 理论 %d 字节', strlen($raw), strlen($stuffed)));

    // 5) dot-stuffing 生效：text/plain 中行首 "." 应被双写为 ".."（线上一处，理论 stuffed 对应一致）
    $chk('行首点已被 dot-stuffing 处理', strpos($raw, "\r\n..") !== false || strpos($stuffed, "\r\n..") === false);

    echo "\n==== SMTP 报文线上合规测试: $pass 通过 / $fail 失败 ====\n";
} finally {
    proc_terminate($proc);
    proc_close($proc);
    @unlink($dump);
    @unlink($ready);
    @unlink($serverFile);
}

exit($fail > 0 ? 1 : 0);
