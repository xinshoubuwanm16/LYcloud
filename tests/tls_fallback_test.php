<?php
/**
 * Mailer TLS 证书验证降级测试
 *
 * 场景：本机自签名证书 SMTP mock（模拟宝塔服务器缺 CA 根证书包导致
 * 严格验证必败）→ 断言 1) 严格模式原始错误含证书细节 2) send() 自动
 * 降级宽松验证并投递成功 3) 降级后 lastError 为空。
 * 依赖 proc_open + openssl 命令，缺失时 SKIP（不计失败）。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Mail\Mailer;

$pass = 0; $fail = 0; $skip = 0;
$chk = function (string $name, bool $cond, string $extra = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  ✔ $name\n"; }
    else { $fail++; echo "  ✘ $name" . ($extra !== '' ? "  -- $extra" : '') . "\n"; }
};

if (!function_exists('proc_open')) {
    echo "SKIP: proc_open 不可用，无法启动 mock SMTP\n";
    exit(0);
}

// ---------- 准备自签名证书 ----------
$dir = sys_get_temp_dir() . '/ly_tls_test_' . getmypid();
@mkdir($dir, 0700, true);
$cert = "$dir/server.pem";
if (!file_exists($cert)) {
    @shell_exec(sprintf(
        'openssl req -x509 -newkey rsa:2048 -keyout %s -in /dev/null -out %s -days 2 -nodes -subj "/CN=fake-smtp.local" 2>/dev/null',
        escapeshellarg($cert),
        escapeshellarg($cert)
    ));
}
if (!file_exists($cert) || filesize($cert) < 100) {
    // openssl 一条命令同时出 key+cert 需分开；改用两文件合并
    @shell_exec(sprintf(
        'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 2 -nodes -subj "/CN=fake-smtp.local" 2>/dev/null && cat %s %s > %s',
        escapeshellarg("$dir/key.pem"),
        escapeshellarg("$dir/cert.pem"),
        escapeshellarg("$dir/cert.pem"),
        escapeshellarg("$dir/key.pem"),
        escapeshellarg($cert)
    ));
}
if (!file_exists($cert) || filesize($cert) < 100) {
    echo "SKIP: openssl 不可用，无法生成自签名证书\n";
    exit(0);
}

// ---------- 写入 mock SMTP 服务器 ----------
$port = 3467 + (getmypid() % 50);
$serverPhp = <<< 'PHPEOF'
<?php
$argvPort = (int)$argv[1];
$certFile = $argv[2];
$ctx = stream_context_create(['ssl' => [
    'local_cert' => $certFile, 'allow_self_signed' => true,
    'verify_peer' => false, 'verify_peer_name' => false,
]]);
$srv = @stream_socket_server("ssl://127.0.0.1:$argvPort", $en, $es, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $ctx);
if (!$srv) { fwrite(STDERR, "listen failed: $es\n"); exit(1); }
file_put_contents($argv[3], '1');
while (true) {
    $read = [$srv]; $w = null; $e = null;
    if (stream_select($read, $w, $e, 5) === false) break;
    $fp = @stream_socket_accept($srv, 0);
    if (!$fp) continue;
    fwrite($fp, "220 mock ESMTP ready\r\n");
    $inData = false;
    while (($line = fgets($fp, 4096)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 10));
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') { $inData = false; fwrite($fp, "250 queued OK\r\n"); }
            continue;
        }
        if (str_starts_with($cmd, 'EHLO')) { fwrite($fp, "250-mock\r\n250-AUTH LOGIN\r\n250 OK\r\n"); }
        elseif (str_starts_with($cmd, 'AUTH LOGIN')) {
            fwrite($fp, "334 VXNlcm5hbWU6\r\n"); fgets($fp, 4096);
            fwrite($fp, "334 UGFzc3dvcmQ6\r\n"); fgets($fp, 4096);
            fwrite($fp, "235 authentication successful\r\n");
        }
        elseif (str_starts_with($cmd, 'MAIL FROM')) { fwrite($fp, "250 OK\r\n"); }
        elseif (str_starts_with($cmd, 'RCPT TO'))  { fwrite($fp, "250 OK\r\n"); }
        elseif (str_starts_with($cmd, 'DATA'))     { $inData = true; fwrite($fp, "354 go ahead\r\n"); }
        elseif (str_starts_with($cmd, 'QUIT'))     { fwrite($fp, "221 bye\r\n"); break; }
        else { fwrite($fp, "500 unknown\r\n"); }
    }
    fclose($fp);
}
PHPEOF;
$serverFile = "$dir/mock_server.php";
file_put_contents($serverFile, $serverPhp);
$readyFile = "$dir/ready.flag";
@unlink($readyFile);

$proc = proc_open(
    PHP_BINARY . ' ' . escapeshellarg($serverFile) . ' ' . $port . ' ' . escapeshellarg($cert) . ' ' . escapeshellarg($readyFile),
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes
);
if (!is_resource($proc)) {
    echo "SKIP: mock SMTP 启动失败\n";
    exit(0);
}
stream_set_blocking($pipes[2], false);
for ($i = 0; $i < 40 && !file_exists($readyFile); $i++) { usleep(50000); }

if (!file_exists($readyFile)) {
    echo "SKIP: mock SMTP 就绪超时（端口冲突？）\n";
    $err = stream_get_contents($pipes[2]);
    if ($err !== '') echo "  server stderr: " . trim($err) . "\n";
    proc_terminate($proc);
    exit(0);
}

$cfg = [
    'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'ssl', 'timeout' => 6,
    'username' => 'tester@ly.local', 'password' => 'anypass',
    'from_email' => 'no-reply@ly.local', 'from_name' => 'LY测试',
];

try {
    echo "== TLS 降级（自签名证书 → 宽松验证重试）==\n";

    // 1) 严格模式原始错误（doSend relaxed=false）应包含证书验证细节
    $m = new Mailer($cfg);
    $rm = new ReflectionMethod(Mailer::class, 'doSend');
    $rm->setAccessible(true);
    $rm->invoke($m, 'user@qq.com', 'strict', '<p>x</p>', '', '', false);
    $strictErr = $m->lastError();
    $chk('严格模式失败且错误含证书/TLS 细节',
        str_contains($strictErr, 'certificate') || str_contains($strictErr, 'SSL') || str_contains($strictErr, 'TLS'),
        mb_substr($strictErr, 0, 100));

    // 2) send() 自动降级并投递成功
    $m2 = new Mailer($cfg);
    $ok = $m2->send('user@qq.com', 'TLS 降级测试', '<p>自签名证书降级投递</p>');
    $chk('send() 自动降级并投递成功', $ok === true, $m2->lastError());

    // 3) 降级成功后 lastError 为空
    $chk('降级成功后 lastError 为空', $m2->lastError() === '', $m2->lastError());

    // 4) isTlsVerifyError 识别单测
    $rc = new ReflectionClass(Mailer::class);
    $fn = $rc->getMethod('isTlsVerifyError');
    $fn->setAccessible(true);
    $chk('识别 certificate verify failed', $fn->invoke(null, 'error:0A000086:SSL routines::certificate verify failed') === true);
    $chk('识别 unable to get local issuer certificate', $fn->invoke(null, 'unable to get local issuer certificate') === true);
    $chk('不误判 AUTH 失败', $fn->invoke(null, 'SMTP AUTH 密码 阶段失败，期望 235，实际：535') === false);

    echo "\n==== TLS 降级测试: $pass 通过 / $fail 失败 ====\n";
} finally {
    proc_terminate($proc);
    proc_close($proc);
    @unlink($readyFile);
    @unlink($serverFile);
    @unlink($cert);
    @$dir && @rmdir($dir);
}

exit($fail > 0 ? 1 : 0);
