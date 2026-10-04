<?php
/**
 * LY云计算 - 安装向导
 * 访问 /install/index.php 进行安装
 */

$root = dirname(__DIR__);
$step = $_GET['step'] ?? '1';
$err  = '';
$done = false;

$configFile = $root . '/config/config.php';
$installed  = is_file($configFile);

// ---------- 环境检测 ----------
$checks = [
    'PHP 版本 >= 7.4'        => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO MySQL 扩展'          => extension_loaded('pdo_mysql'),
    'OpenSSL 扩展（支付宝必需）' => extension_loaded('openssl'),
    'cURL 扩展'               => extension_loaded('curl'),
    'mbstring 扩展'           => extension_loaded('mbstring'),
    'JSON 扩展'               => extension_loaded('json'),
    'config 目录可写'          => is_writable($root . '/config') || is_writable($root),
    'uploads 目录可写'         => is_writable($root . '/uploads') || is_writable($root),
];
$allOk = !in_array(false, $checks, true);

// ---------- 执行安装 ----------
if ($step === '2' && $allOk) {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');

    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $siteName  = trim($_POST['site_name'] ?? 'LY云计算');

    if ($dbName === '' || $dbUser === '') {
        $err = '请填写数据库名称与用户名';
    } elseif ($adminUser === '' || strlen($adminPass) < 6) {
        $err = '管理员账号不能为空，密码至少 6 位';
    } else {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort),
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $dbName) . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            $pdo->exec('USE `' . str_replace('`', '', $dbName) . '`');

            // 导入表结构
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            if ($sql === false) {
                throw new RuntimeException('无法读取 schema.sql');
            }
            $pdo->exec($sql);

            // 写入管理员
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 10]);
            $stmt = $pdo->prepare('INSERT INTO ly_admins (username,password,real_name,status) VALUES (?,?,?,1)
                                   ON DUPLICATE KEY UPDATE password=VALUES(password)');
            $stmt->execute([$adminUser, $hash, '超级管理员']);

            // 更新站点名
            $stmt = $pdo->prepare('INSERT INTO ly_settings (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
            $stmt->execute(['site_name', $siteName]);

            // 写配置文件
            if (!is_dir($root . '/config')) {
                @mkdir($root . '/config', 0755, true);
            }
            $tpl = "<?php\n/**\n * LY云计算 配置文件（由安装向导生成）\n */\n\n"
                . "define('LY_CONFIG', [\n"
                . "    'db' => [\n"
                . "        'host'     => " . var_export($dbHost, true) . ",\n"
                . "        'port'     => " . $dbPort . ",\n"
                . "        'database' => " . var_export($dbName, true) . ",\n"
                . "        'username' => " . var_export($dbUser, true) . ",\n"
                . "        'password' => " . var_export($dbPass, true) . ",\n"
                . "    ],\n"
                . "    'base_path' => '',\n"
                . "    'timezone'  => 'Asia/Shanghai',\n"
                . "]);\n\n"
                . "define('LY_TIMEZONE', 'Asia/Shanghai');\n"
                . "define('LY_VERSION', '1.2.0');\n";

            if (file_put_contents($configFile, $tpl) === false) {
                throw new RuntimeException('无法写入 config/config.php，请检查目录权限');
            }

            $done = true;
            $step = '3';
        } catch (Throwable $e) {
            $err = '安装失败：' . $e->getMessage();
            $step = '2';
        }
    }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 - LY云计算</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#0b1220;color:#e6edf7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.box{width:100%;max-width:680px;background:#131c2e;border:1px solid #22304a;border-radius:16px;padding:36px}
h1{font-size:22px;margin-bottom:6px}
h1 span{color:#3b82f6}
.sub{color:#8ba0bd;font-size:13px;margin-bottom:26px}
.steps{display:flex;gap:10px;margin-bottom:26px}
.steps div{flex:1;text-align:center;font-size:12px;padding:8px;border-radius:8px;background:#1a2540;color:#8ba0bd}
.steps div.on{background:#1d4ed8;color:#fff}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px}
td{padding:9px 12px;border-bottom:1px solid #22304a}
td:last-child{text-align:right}
.ok{color:#22c55e}.no{color:#ef4444}
label{display:block;font-size:13px;color:#8ba0bd;margin:14px 0 6px}
input{width:100%;padding:11px 13px;background:#0d1526;border:1px solid #24314b;border-radius:9px;color:#e6edf7;font-size:14px;outline:none}
input:focus{border-color:#3b82f6}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
button{width:100%;margin-top:24px;padding:13px;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:0;border-radius:10px;color:#fff;font-size:15px;font-weight:600;cursor:pointer}
button:hover{filter:brightness(1.1)}
.alert{padding:12px 14px;border-radius:9px;font-size:13px;margin-bottom:18px}
.alert.err{background:#3b1218;border:1px solid #7f1d1d;color:#fca5a5}
.alert.ok{background:#0f2e1d;border:1px solid #14532d;color:#86efac}
.tip{font-size:12px;color:#6b7f9c;margin-top:8px;line-height:1.6}
a.btn{display:block;text-align:center;margin-top:24px;padding:13px;background:linear-gradient(135deg,#2563eb,#1d4ed8);border-radius:10px;color:#fff;text-decoration:none;font-weight:600}
</style>
</head>
<body>
<div class="box">
    <h1>LY<span>云计算</span> 安装向导</h1>
    <div class="sub">IPv6 宝塔面板主机购买系统 · Nginx + PHP8.2 + MySQL5.7</div>

    <div class="steps">
        <div class="<?= $step === '1' ? 'on' : '' ?>">① 环境检测</div>
        <div class="<?= $step === '2' ? 'on' : '' ?>">② 配置数据库</div>
        <div class="<?= $step === '3' ? 'on' : '' ?>">③ 安装完成</div>
    </div>

    <?php if ($installed && $step !== '3'): ?>
        <div class="alert err">
            检测到系统已安装（config/config.php 已存在）。如需重新安装，请先删除该文件。
        </div>
        <a class="btn" href="/index.php">进入网站首页</a>
    <?php elseif ($step === '1'): ?>
        <table>
            <?php foreach ($checks as $name => $pass): ?>
                <tr>
                    <td><?= h($name) ?></td>
                    <td class="<?= $pass ? 'ok' : 'no' ?>"><?= $pass ? '✔ 通过' : '✘ 不满足' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if (!$allOk): ?>
            <div class="alert err">存在未通过的检测项，请先解决后再继续安装。</div>
        <?php endif; ?>
        <form method="get" action="index.php">
            <input type="hidden" name="step" value="2">
            <button type="submit" <?= $allOk ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>下一步：配置数据库</button>
        </form>
    <?php elseif ($step === '2'): ?>
        <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
        <form method="post" action="index.php?step=2">
            <div class="grid2">
                <div>
                    <label>数据库地址</label>
                    <input name="db_host" value="<?= h($_POST['db_host'] ?? '127.0.0.1') ?>">
                </div>
                <div>
                    <label>数据库端口</label>
                    <input name="db_port" value="<?= h($_POST['db_port'] ?? '3306') ?>">
                </div>
            </div>
            <label>数据库名称</label>
            <input name="db_name" value="<?= h($_POST['db_name'] ?? 'lycloud') ?>">
            <div class="grid2">
                <div>
                    <label>数据库用户名</label>
                    <input name="db_user" value="<?= h($_POST['db_user'] ?? 'lycloud') ?>">
                </div>
                <div>
                    <label>数据库密码</label>
                    <input name="db_pass" value="<?= h($_POST['db_pass'] ?? '') ?>">
                </div>
            </div>

            <label>网站名称</label>
            <input name="site_name" value="<?= h($_POST['site_name'] ?? 'LY云计算') ?>">

            <div class="grid2">
                <div>
                    <label>管理员账号</label>
                    <input name="admin_user" value="<?= h($_POST['admin_user'] ?? 'admin') ?>">
                </div>
                <div>
                    <label>管理员密码（≥6位）</label>
                    <input name="admin_pass" type="password" value="">
                </div>
            </div>

            <button type="submit">开始安装</button>
        </form>
    <?php else: ?>
        <div class="alert ok">
            安装完成！数据库表结构已导入，管理员账号已创建。
        </div>
        <div class="tip">
            <strong>安全提示：</strong>安装完成后请删除 <code>install/</code> 目录，避免被他人重复执行安装。<br>
            后台地址：<code>/index.php?r=admin/auth/login</code> 或 <code>/admin</code>
        </div>
        <a class="btn" href="/index.php">进入网站首页</a>
        <a class="btn" style="background:#1e293b;margin-top:10px" href="/index.php?r=admin/auth/login">进入管理后台</a>
    <?php endif; ?>
</div>
</body>
</html>
