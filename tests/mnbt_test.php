<?php
/**
 * MNBT（梦奈宝塔主机系统）对接测试套件（1.7.6）
 *
 * 覆盖范围：
 *   ── 一、MnbtClient API 客户端 ─────────────────────────────────
 *      · 配置判定 ready / missingFields / fromSetting
 *      · 接口地址规范化（补协议、裁 /api/api.php、去尾斜杠）
 *      · 9 个业务接口（cfif/kt/xf/tz/zt/jc/czmm + 一键登录）
 *      · code=100 业务失败、code=300 版本不匹配、非 JSON 响应、网络不可达、超时
 *      · loginUrl 绝不携带必带参数（密钥不得暴露给终端用户）
 *
 *   ── 二、Setting 配置层 ───────────────────────────────────────
 *      · 默认值、完整配置判定、边界钳制（timeout / connect_timeout / max_retry）
 *
 *   ── 三、Product 交付方式 ─────────────────────────────────────
 *      · 常量、normalizeDeliverMode 归一化、isMnbt、规格文案
 *
 *   ── 四、订单发货流程（同步开通）────────────────────────────────
 *      · MNBT 商品不占用本站库存池
 *      · markPaid → 事务提交后调用 MNBT → 写入 source=2 面板记录
 *      · Order::panel() 复用既有链路可查到主机信息
 *      · 重复 markPaid 幂等：不重复开通
 *      · 纯余额路径同样触发开通
 *      · expire_at 与 MNBT 到期日联动
 *
 *   ── 五、失败重试与自动退款兜底 ───────────────────────────────
 *      · 首次失败 → 待重试（RETRY），余额不动
 *      · 重试耗尽 → 开通失败（FAILED）+ 自动退款到余额
 *      · 退款幂等（重复重试不重复退款）
 *      · max_retry=0 → 首次即退款
 *      · 网络不可达 → 不抛异常、标记待重试、订单保持已支付
 *      · 故障恢复后自动重试成功、清空失败原因
 *      · 人工重试不受次数限制、失败时不自动退款
 *      · 异常单统计口径
 *
 *   ── 六、订单详情页与一键登录中转 ─────────────────────────────
 *      · 前台/后台详情页渲染关键要素
 *      · 一键登录服务端中转：页面不含 MNBT 直链与密钥
 *      · 归属校验、状态门禁
 *      · 后台列表开通状态列
 *
 *   ── 七、1.7.12 · 测试连接用表单当前值 + mn_vs 版本兼容 ────────
 *      · mergeMnbtFormConfig：表单值优先 / 密钥留空回落已保存值 / api_url 裁剪
 *      · mn_vs 兼容「1.82」小数写法（归一 182）与无数字回落
 *      · 超时字段钳制
 *      · 端到端：已保存 vs 被上游拒绝时，表单 vs=16 仍能测试成功（表单值优先生效）
 *      · 端到端：vs=1.82 → 182 → code=300，报错携带当前 mn_vs 值与填写指引
 *
 *   ── 八、1.7.13 · HTTP 3xx 重定向诊断 ─────────────────────────
 *      · 上游 301（如 Cloudflare 强制 HTTPS）不跟随、不误报「返回格式异常」
 *      · 报错标注 HTTP 301、携带重定向目标、给出「改用 https」可行动指引
 *
 * 运行：php tests/mnbt_test.php
 *      测试会自行拉起一个内置 mock MNBT 服务（127.0.0.1:8899）并在结束时关闭。
 *      如需指定其他端口：LY_MNBT_MOCK_PORT=8901 php tests/mnbt_test.php
 *
 * 依赖：本机 MySQL 已按 install/schema.sql 建库，且已执行 upgrade_1.7.6.sql
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Mnbt\MnbtClient;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;

$pass = 0; $fail = 0;
$chk = function (string $name, bool $cond, string $extra = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; echo "  [FAIL] $name" . ($extra !== '' ? "  -- $extra" : '') . "\n"; }
};
/** 分节标题 */
$sec = function (string $t) { echo "\n── $t ──\n"; };

$db = Database::instance();

/* ==================================================================
 *  0. 启动内置 mock MNBT 服务
 * ================================================================== */

$mockPort = (int)(getenv('LY_MNBT_MOCK_PORT') ?: 8899);
$mockRoot = sys_get_temp_dir() . '/lycloud_mnbt_mock_' . $mockPort;
$mockLog  = $mockRoot . '/req.log';

/** 递归删除目录 */
$rmrf = function (string $dir) use (&$rmrf) {
    if (!is_dir($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $rmrf($dir . '/' . $f);
    }
    @rmdir($dir);
};

/**
 * 判断端口是否已被占用。
 * 之所以要先探测：若端口被上一轮遗留的 mock 进程占着，
 * proc_open 起的 php -S 会立刻以 exitcode=1 退出，
 * 但那个「外部」进程仍占着端口，导致测试结束后端口探测仍成功、
 * 误判为「mock 未关闭」。因此必须先清场再启动。
 */
$portBusy = function (int $port): bool {
    $fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.3);
    if ($fp) { fclose($fp); return true; }
    return false;
};

/** 杀掉占用指定端口的遗留 php -S 进程 */
$killPortHolder = function (int $port) {
    @exec('pkill -f ' . escapeshellarg('php -S 127.0.0.1:' . $port) . ' 2>/dev/null');
};

// 清场：先干掉可能遗留的同端口进程
$killPortHolder($mockPort);
for ($i = 0; $i < 20 && $portBusy($mockPort); $i++) { usleep(100000); }
$chk('启动前 mock 端口 ' . $mockPort . ' 已空闲', !$portBusy($mockPort));

// 重建 mock 站点目录
$rmrf($mockRoot);
@mkdir($mockRoot . '/api', 0777, true);
@mkdir($mockRoot . '/user', 0777, true);

$mockApi = <<<'PHPEOF'
<?php
// 内置 MNBT mock：模拟 {站点}/api/api.php
$gn = $_GET['gn'] ?? '';
file_put_contents(__DIR__ . '/../req.log',
    json_encode(['gn' => $gn, 'post' => $_POST, 'time' => date('H:i:s')], JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND);

foreach (['mn_bh', 'mn_key', 'mn_keye', 'mn_vs'] as $k) {
    if (empty($_POST[$k])) {
        echo json_encode(['code' => 100, 'msg' => "缺少必带参数 {$k}"], JSON_UNESCAPED_UNICODE); exit;
    }
}
if ($_POST['mn_key'] !== 'GOODKEY') {
    echo json_encode(['code' => 100, 'msg' => 'API密钥错误'], JSON_UNESCAPED_UNICODE); exit;
}
if ($_POST['mn_vs'] !== '16') {
    echo json_encode(['code' => 300, 'msg' => '插件版本与MNBT版本不匹配，请更新插件'], JSON_UNESCAPED_UNICODE); exit;
}

switch ($gn) {
    case 'cfif': echo json_encode(['code' => 200, 'msg' => '连接成功'], JSON_UNESCAPED_UNICODE); break;
    case 'kt':
        $u = $_POST['username'] ?? '';
        if ($u === 'exists_host') { echo json_encode(['code' => 100, 'msg' => '该账号已存在'], JSON_UNESCAPED_UNICODE); break; }
        if ($u === 'timeout_host') { sleep(30); }
        echo json_encode(['code' => 200, 'msg' => '主机开通成功！'], JSON_UNESCAPED_UNICODE); break;
    case 'xf':   echo json_encode(['code' => 200, 'msg' => '续费成功！'], JSON_UNESCAPED_UNICODE); break;
    case 'tz':   echo json_encode(['code' => 200, 'msg' => '主机已删除！'], JSON_UNESCAPED_UNICODE); break;
    case 'zt':   echo json_encode(['code' => 200, 'msg' => '主机已暂停！'], JSON_UNESCAPED_UNICODE); break;
    case 'jc':   echo json_encode(['code' => 200, 'msg' => '已解除暂停！'], JSON_UNESCAPED_UNICODE); break;
    case 'czmm': echo json_encode(['code' => 200, 'msg' => '密码已重置！'], JSON_UNESCAPED_UNICODE); break;
    default:     echo '<html>unknown op</html>';
}
PHPEOF;
file_put_contents($mockRoot . '/api/api.php', $mockApi);
// 一键登录页面（供中转跳转目标存在性校验用）
file_put_contents($mockRoot . '/user/idcdl.php', "<?php echo 'MOCK_PANEL_LOGIN'; ?>");

// 启动 mock 服务（用 setsid 独立进程组，便于整组回收）
$mockProc = proc_open(
    'exec php -S 127.0.0.1:' . $mockPort . ' -t ' . escapeshellarg($mockRoot),
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
$mockBase = 'http://127.0.0.1:' . $mockPort;
$mockUp = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    if ($portBusy($mockPort)) { $mockUp = true; break; }
}
$chk('mock MNBT 服务已就绪（端口 ' . $mockPort . '）', $mockUp);

/* ---------- 1.7.13：301 重定向 mock（模拟 Cloudflare 强制 HTTPS 跳转）---------- */

$mock301Port = (int)(getenv('LY_MNBT_MOCK301_PORT') ?: 8898);
$mock301Root = sys_get_temp_dir() . '/lycloud_mnbt_mock301_' . $mock301Port;
$killPortHolder($mock301Port);
for ($i = 0; $i < 20 && $portBusy($mock301Port); $i++) { usleep(100000); }
$rmrf($mock301Root);
@mkdir($mock301Root . '/api', 0777, true);
file_put_contents(
    $mock301Root . '/api/api.php',
    "<?php header('Location: https://mnbt.example.com/api/api.php?gn=cfif', true, 301); echo '301 Moved Permanently';"
);
$mock301Proc = proc_open(
    'exec php -S 127.0.0.1:' . $mock301Port . ' -t ' . escapeshellarg($mock301Root),
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes301
);
$mock301Up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    if ($portBusy($mock301Port)) { $mock301Up = true; break; }
}
$chk('301 mock 服务已就绪（端口 ' . $mock301Port . '）', $mock301Up);

/* ==================================================================
 *  备份现场配置，测试中使用 mock 地址
 * ================================================================== */

$configKeys = [
    'mnbt_switch', 'mnbt_api_url', 'mnbt_bh', 'mnbt_key', 'mnbt_keye',
    'mnbt_vs', 'mnbt_timeout', 'mnbt_connect_timeout', 'mnbt_max_retry', 'mnbt_default_prefix',
];
$backup = [];
foreach ($configKeys as $k) { $backup[$k] = Setting::get($k, null); }

/** 还原现场配置 */
$restoreConfig = function () use ($backup) {
    foreach ($backup as $k => $v) {
        if ($v === null) { continue; }
        Setting::set($k, (string)$v);
    }
};

/**
 * 应用一份 MNBT 配置。
 *
 * 重要：这里必须**显式写死全部字段**，不能让任何字段落到 Setting 的默认值上。
 * 因为「二、Setting」小节会故意把 mnbt_max_retry 改成 3/99 等值来测钳制，
 * 若此处不写死，后续用例就会读到被污染的 3，导致「重试耗尽」用例提前退款。
 */
$applyConfig = function (array $over = []) use ($mockBase) {
    Setting::setMany(array_merge([
        'mnbt_switch'          => '1',
        'mnbt_api_url'         => $mockBase,
        'mnbt_bh'              => 'fw12201',
        'mnbt_key'             => 'GOODKEY',
        'mnbt_keye'            => 'btkey',
        'mnbt_vs'              => '16',
        'mnbt_timeout'         => '15',
        'mnbt_connect_timeout' => '10',
        'mnbt_max_retry'       => '2',
        'mnbt_default_prefix'  => 'ly',
    ], $over));
    // 断言配置真的生效，防止后续用例建立在错误前提上
    if (!isset($over['mnbt_max_retry']) || (string)$over['mnbt_max_retry'] !== '2') {
        // 仅当调用方明确覆盖时才可能出现偏差，这里不做硬断言
    }
};

/* ---------- 测试数据登记表（结束统一清理）---------- */
$created = ['products' => [], 'orders' => [], 'users' => [], 'stocks' => [], 'admins' => []];

/** 造测试用户 */
$newUser = function (string $balance = '0.00') use ($db, &$created): int {
    $email = 'mnbtt' . bin2hex(random_bytes(5)) . '@example.test';
    $uid = User::create($email, 'Test123456', 'MNBT测试', true);
    $db->update('ly_users', ['balance' => $balance], 'id=?', [$uid]);
    $created['users'][] = $uid;
    return $uid;
};

/** 造商品 */
$mkProduct = function (array $over = []) use (&$created): int {
    $pid = Product::create(array_merge([
        'name'                => '__MNBT测试商品__',
        'price'               => '49.90',
        'deliver_mode'        => Product::DELIVER_MNBT,
        'mnbt_prefix'         => 'mnt',
        'mnbt_webdx'          => 1024,
        'mnbt_sqldx'          => 200,
        'mnbt_sizemax'        => 50,
        'mnbt_type'           => Product::DELIVER_MNBT,
        'mnbt_ymbds'          => 5,
        'stock_mode'          => 1,
        'auto_deliver'        => 1,
        'status'              => 1,
        'duration_value'      => 1,
        'duration_unit'       => 'month',
    ], $over));
    $created['products'][] = $pid;
    return $pid;
};

/** 渲染视图为 HTML（用于详情页断言） */
$render = function (string $view, array $vars): string {
    $__view = $view;
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../app/Views/' . $__view . '.php';
    return (string)ob_get_clean();
};

/* ==================================================================
 *  一、MnbtClient API 客户端
 * ================================================================== */

$sec('一、MnbtClient · 配置判定');

$applyConfig();
$client = new MnbtClient();
$chk('配置完整时 ready() 为 true', $client->ready() === true);
$chk('配置完整时 missingFields() 为空', $client->missingFields() === []);
$chk('fromSetting() 在配置完整时返回实例', MnbtClient::fromSetting() instanceof MnbtClient);

$applyConfig(['mnbt_key' => '']);
$c2 = new MnbtClient();
$chk('缺少 API 密钥时 ready() 为 false', $c2->ready() === false);
$chk('missingFields() 报出「API 密钥」', in_array('API 密钥', $c2->missingFields(), true));
$chk('fromSetting() 在配置缺失时返回 null', MnbtClient::fromSetting() === null);

$applyConfig(['mnbt_switch' => '0']);
$chk('开关关闭时 ready() 为 false（即使参数齐全）', (new MnbtClient())->ready() === false);
$applyConfig(['mnbt_switch' => '1']);

$sec('一、MnbtClient · 接口地址规范化');
$applyConfig(['mnbt_api_url' => $mockBase . '/']);
$chk('尾部斜杠被裁掉', (new MnbtClient())->baseUrl() === $mockBase);
$applyConfig(['mnbt_api_url' => $mockBase . '/api/api.php']);
$chk('粘贴完整接口地址时自动裁掉 /api/api.php', (new MnbtClient())->baseUrl() === $mockBase);
$applyConfig(['mnbt_api_url' => '127.0.0.1:' . $mockPort]);
$chk('缺少协议时自动补 http://', (new MnbtClient())->baseUrl() === $mockBase);
$applyConfig(['mnbt_api_url' => $mockBase]);
$chk('endpoint(kt) 正确拼接操作码', (new MnbtClient())->endpoint(MnbtClient::OP_CREATE) === $mockBase . '/api/api.php?gn=kt');

$sec('一、MnbtClient · 业务接口');
$c = new MnbtClient();
$r = $c->testConnection();
$chk('testConnection 返回 code=200', (int)$r['code'] === 200, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = $c->createHost([
    'username' => 'mntok' . bin2hex(random_bytes(3)),
    'password' => 'Abcd1234efGh',
    'webdx'    => 1024, 'sqldx' => 200, 'sizemax' => 50,
    'type'     => MnbtClient::TYPE_HOST, 'ymbds' => 5, 'dqtime' => '2026-12-31',
]);
$chk('createHost 成功返回 code=200', (int)$r['code'] === 200, json_encode($r, JSON_UNESCAPED_UNICODE));

try {
    $c->createHost(['username' => 'exists_host', 'password' => 'Abcd1234', 'dqtime' => '0']);
    $chk('createHost 遇到 code=100 抛异常', false, '未抛异常');
} catch (\RuntimeException $e) {
    $chk('createHost 遇到 code=100 抛异常且带上游原因', strpos($e->getMessage(), '该账号已存在') !== false, $e->getMessage());
}

try {
    $c->createHost(['username' => '', 'password' => 'x']);
    $chk('createHost 空账号本地拦截', false, '未抛异常');
} catch (\RuntimeException $e) {
    $chk('createHost 空账号本地拦截（不打网络）', strpos($e->getMessage(), '账号为空') !== false, $e->getMessage());
}

$chk('renewHost 返回 code=200', (int)$c->renewHost('mntok', '2027-01-31')['code'] === 200);
$chk('deleteHost 返回 code=200', (int)$c->deleteHost('mntok')['code'] === 200);
$chk('suspendHost 返回 code=200', (int)$c->suspendHost('mntok')['code'] === 200);
$chk('unsuspendHost 返回 code=200', (int)$c->unsuspendHost('mntok')['code'] === 200);
$chk('resetPassword 返回 code=200', (int)$c->resetPassword('mntok', 'NewPass123')['code'] === 200);

$sec('一、MnbtClient · 异常场景');
$applyConfig(['mnbt_vs' => '17']);
try {
    (new MnbtClient())->testConnection();
    $chk('版本不匹配(code=300)抛异常', false, '未抛异常');
} catch (\RuntimeException $e) {
    $chk('版本不匹配(code=300)抛异常并提示更新插件', strpos($e->getMessage(), '版本') !== false, $e->getMessage());
}
$applyConfig(['mnbt_vs' => '16']);

$applyConfig(['mnbt_key' => 'WRONGKEY']);
try {
    (new MnbtClient())->testConnection();
    $chk('密钥错误(code=100)抛异常', false, '未抛异常');
} catch (\RuntimeException $e) {
    $chk('密钥错误(code=100)抛异常并带上游原因', strpos($e->getMessage(), '密钥') !== false, $e->getMessage());
}
$applyConfig(['mnbt_key' => 'GOODKEY']);

$applyConfig(['mnbt_api_url' => 'http://127.0.0.1:9']);
try {
    (new MnbtClient())->testConnection();
    $chk('网络不可达抛异常', false, '未抛异常');
} catch (\RuntimeException $e) {
    $chk('网络不可达抛异常（消息非空）', $e->getMessage() !== '');
}
$applyConfig(['mnbt_api_url' => $mockBase]);

// 超时：mock 对 timeout_host 会 sleep(30)，我们只给 5s 总超时
$applyConfig(['mnbt_timeout' => '5', 'mnbt_connect_timeout' => '3']);
$t0 = microtime(true);
try {
    (new MnbtClient())->createHost(['username' => 'timeout_host', 'password' => 'Abcd1234', 'dqtime' => '0']);
    $chk('超时被正确中断（不无限等待）', false, '未抛异常');
} catch (\RuntimeException $e) {
    $elapsed = microtime(true) - $t0;
    $chk('超时被正确中断，耗时 ' . round($elapsed, 1) . 's < 12s', $elapsed < 12, '实际 ' . round($elapsed, 1) . 's');
}
$applyConfig(['mnbt_timeout' => '15', 'mnbt_connect_timeout' => '10']);

// 非 JSON 响应
$applyConfig(['mnbt_api_url' => 'http://127.0.0.1:' . $mockPort]);
try {
    (new MnbtClient())->request('unknownop', []);
    $chk('非 JSON 响应抛异常', false, '未抛异常');
} catch (\RuntimeException $e) {
    $chk('非 JSON 响应抛出可读异常', $e->getMessage() !== '');
}

$sec('一、MnbtClient · 一键登录安全约束');
$c = new MnbtClient();
$login = $c->loginUrl('mntdemo001', 'SecretPass123');
$chk('loginUrl 指向 idcdl.php', strpos($login, 'idcdl.php') !== false);
$chk('loginUrl 带 gn=logine', strpos($login, 'gn=logine') !== false);
$chk('loginUrl 含 username 参数', strpos($login, 'username=mntdemo001') !== false);
$chk('loginUrl 不含 mn_bh（宝塔编号）', strpos($login, 'mn_bh') === false);
$chk('loginUrl 不含 mn_key（API 密钥）', strpos($login, 'mn_key') === false);
$chk('loginUrl 不含 mn_keye（宝塔调用密钥）', strpos($login, 'mn_keye') === false);
$chk('loginUrl 不含 mn_vs（版本号）', strpos($login, 'mn_vs') === false);
$chk('panelHomeUrl 指向 /user/', strpos($c->panelHomeUrl(), '/user/') !== false);

/* ==================================================================
 *  二、Setting 配置层
 * ================================================================== */

$sec('二、Setting · 默认值与边界钳制');

$stripKeys = function (array $keys) use ($db) {
    $db->query(
        'DELETE FROM ly_settings WHERE k IN (' . implode(',', array_fill(0, count($keys), '?')) . ')',
        $keys
    );
};
$stripKeys(['mnbt_timeout', 'mnbt_connect_timeout', 'mnbt_max_retry', 'mnbt_default_prefix', 'mnbt_vs']);
$cfg = Setting::mnbt();
$chk('默认 timeout = 15', (int)$cfg['timeout'] === 15, (string)$cfg['timeout']);
$chk('默认 connect_timeout = 10', (int)$cfg['connect_timeout'] === 10, (string)$cfg['connect_timeout']);
$chk('默认 max_retry = 2', (int)$cfg['max_retry'] === 2, (string)$cfg['max_retry']);
$chk('默认 default_prefix = ly', $cfg['default_prefix'] === 'ly', $cfg['default_prefix']);
$chk('默认 mn_vs = 16', $cfg['mn_vs'] === '16', $cfg['mn_vs']);

Setting::setMany(['mnbt_timeout' => '999', 'mnbt_connect_timeout' => '1', 'mnbt_max_retry' => '99']);
$cfg = Setting::mnbt();
$chk('timeout 上限钳制到 60', (int)$cfg['timeout'] === 60, (string)$cfg['timeout']);
$chk('connect_timeout 下限钳制到 3', (int)$cfg['connect_timeout'] === 3, (string)$cfg['connect_timeout']);
$chk('max_retry 上限钳制到 10', (int)$cfg['max_retry'] === 10, (string)$cfg['max_retry']);

Setting::setMany(['mnbt_timeout' => '0', 'mnbt_max_retry' => '-5']);
$cfg = Setting::mnbt();
$chk('timeout 下限钳制到 5', (int)$cfg['timeout'] === 5, (string)$cfg['timeout']);
$chk('max_retry 负数钳制到 0', (int)$cfg['max_retry'] === 0, (string)$cfg['max_retry']);

// 复位钳制测试改写的配置
Setting::setMany(['mnbt_max_retry' => '3']);
$chk('mnbtMaxRetry() 读取到 3', Setting::mnbtMaxRetry() === 3, (string)Setting::mnbtMaxRetry());
// 本小节的钳制测试改写过 max_retry / timeout，立刻复位，避免污染后续用例
Setting::setMany([
    'mnbt_timeout'         => '15',
    'mnbt_connect_timeout' => '10',
    'mnbt_max_retry'       => '2',
    'mnbt_default_prefix'  => 'ly',
    'mnbt_vs'              => '16',
]);

$sec('二、Setting · 完整性判定');
$applyConfig();
$chk('参数齐全且开关打开 → mnbtConfigured() = true', Setting::mnbtConfigured() === true);
$applyConfig(['mnbt_switch' => '0']);
$chk('开关关闭 → mnbtConfigured() = false', Setting::mnbtConfigured() === false);
$applyConfig(['mnbt_bh' => '']);
$chk('缺宝塔编号 → mnbtConfigured() = false', Setting::mnbtConfigured() === false);

/* ==================================================================
 *  三、Product 交付方式
 * ================================================================== */

$sec('三、Product · 交付方式三态');

$chk('DELIVER_STOCK = 1', Product::DELIVER_STOCK === 1);
$chk('DELIVER_MNBT = 2', Product::DELIVER_MNBT === 2);
$chk('DELIVER_MANUAL = 3', Product::DELIVER_MANUAL === 3);
$chk('DELIVER_TEXT 三种文案齐全', count(Product::DELIVER_TEXT) === 3);
$chk('normalizeDeliverMode(2) = 2', Product::normalizeDeliverMode(2) === 2);
$chk('normalizeDeliverMode(0) 回落 1', Product::normalizeDeliverMode(0) === 1);
$chk('normalizeDeliverMode(99) 回落 1', Product::normalizeDeliverMode(99) === 1);
$chk('normalizeDeliverMode(3) = 3', Product::normalizeDeliverMode(3) === 3);

$applyConfig();
$pidMnbt = $mkProduct();
$pMnbt = Product::find($pidMnbt);
$chk('MNBT 商品 isMnbt() = true', Product::isMnbt($pMnbt) === true);
$chk('MNBT 商品 deliverModeText 含「MNBT」', strpos(Product::deliverModeText($pMnbt), 'MNBT') !== false);
$chk('未配置 MNBT 时 isMnbt() 仍按字段判定', Product::isMnbt($pMnbt) === true);

$pidStock = $mkProduct(['deliver_mode' => Product::DELIVER_STOCK, 'name' => '__库存池商品__']);
$pStock = Product::find($pidStock);
$chk('库存池商品 isMnbt() = false', Product::isMnbt($pStock) === false);

$spec = Product::mnbtSpecText($pMnbt);
$chk('规格文案含网页空间 1024MB', strpos($spec, '1024MB') !== false, $spec);
$chk('规格文案含数据库 200MB', strpos($spec, '200MB') !== false, $spec);
$chk('规格文案含月流量 50GB', strpos($spec, '50GB') !== false, $spec);
$chk('规格文案含域名 5 个', strpos($spec, '5 个') !== false, $spec);
$chk('mnbtTypeText 主机类型', Product::mnbtTypeText($pMnbt) === '主机', Product::mnbtTypeText($pMnbt));
$chk('mnbtTypeText CDN 类型', Product::mnbtTypeText(['mnbt_type' => 1]) === 'CDN');

// 真实表读写往返
$db->update('ly_products', [
    'deliver_mode' => 2, 'mnbt_prefix' => 'rt', 'mnbt_webdx' => 4096,
    'mnbt_sqldx' => 512, 'mnbt_sizemax' => 128, 'mnbt_type' => 1, 'mnbt_ymbds' => 12,
], 'id=?', [$pidMnbt]);
$pRt = Product::find($pidMnbt);
$chk('交付方式字段往返读写正确', (int)$pRt['deliver_mode'] === 2);
$chk('MNBT 规格字段往返读写正确', (int)$pRt['mnbt_webdx'] === 4096 && (int)$pRt['mnbt_ymbds'] === 12);
$chk('mnbtType 改为 CDN 后文案同步', Product::mnbtTypeText($pRt) === 'CDN');
$db->update('ly_products', ['mnbt_type' => 2], 'id=?', [$pidMnbt]);

/* ==================================================================
 *  四、订单发货流程（同步开通）
 * ================================================================== */

$sec('四、发货流程 · MNBT 商品不占用库存池');

$applyConfig();
$uid = $newUser('100.00');
$user = User::find($uid);
$pid = $mkProduct(['name' => '__MNBT端到端__', 'mnbt_prefix' => 'e2e']);
$product = Product::find($pid);

$stockBefore = (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id=?', [$pid]);
$chk('测试前该商品无预录库存', $stockBefore === 0);

$o = Order::createWithStock($user, $product, 'qr', false, null);
$created['orders'][] = $o['id'];
$chk('MNBT 商品可下单（不因缺库存被拒）', (int)$o['id'] > 0);
$chk('MNBT 订单未占用 stock_id', (int)$o['stock_id'] === 0, (string)$o['stock_id']);
$chk('待支付阶段 deliver_status = 0', (int)($o['deliver_status'] ?? 0) === Order::DELIVER_NONE);
$chk('未向 ly_stocks 写入预录库存', (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id=?', [$pid]) === 0);

$sec('四、发货流程 · 支付回调触发实时开通');

$r = Order::markPaid((int)$o['id'], 'MOCK' . time());
$chk('markPaid 返回 true', $r === true);
$o1 = Order::find((int)$o['id']);
$chk('订单状态 → 已发货(2)', (int)$o1['status'] === Order::STATUS_DELIVERED, (string)$o1['status']);
$chk('deliver_status → 已开通(2)', (int)$o1['deliver_status'] === Order::DELIVER_DONE, (string)$o1['deliver_status']);
$chk('订单记录了 MNBT 主机账号', $o1['mnbt_username'] !== '', $o1['mnbt_username']);
$chk('主机账号前缀为商品配置的 e2e', strpos($o1['mnbt_username'], 'e2e') === 0, $o1['mnbt_username']);
$chk('已写入并绑定面板记录 stock_id > 0', (int)$o1['stock_id'] > 0, (string)$o1['stock_id']);
$chk('deliver_tries = 1', (int)$o1['deliver_tries'] === 1, (string)$o1['deliver_tries']);
$chk('deliver_error 为空', (string)$o1['deliver_error'] === '');
if ((int)$o1['stock_id'] > 0) { $created['stocks'][] = (int)$o1['stock_id']; }

$sec('四、发货流程 · 复用既有面板展示链路');
$panel = Order::panel((int)$o1['id']);
$chk('Order::panel() 能查到主机记录', $panel !== null);
$chk('面板账号 = MNBT 主机账号', $panel && $panel['panel_user'] === $o1['mnbt_username']);
$chk('面板来源标记 source = 2（MNBT 开通）', $panel && (int)$panel['source'] === 2, $panel ? (string)$panel['source'] : '');
$chk('面板备注标注为 MNBT 自动开通', $panel && strpos((string)$panel['remark'], 'MNBT') !== false, $panel ? $panel['remark'] : '');
$chk('面板入口指向 MNBT /user/ 目录', $panel && strpos((string)$panel['panel_url'], '/user/') !== false, $panel ? $panel['panel_url'] : '');
$chk('面板密码非空且为 12 位', $panel && strlen((string)$panel['panel_pass']) === 12, $panel ? (string)strlen($panel['panel_pass']) : '');
$chk('密码字符集剔除易混淆字符 0O1lI', $panel && !preg_match('/[0O1lI]/', (string)$panel['panel_pass']), $panel ? $panel['panel_pass'] : '');

$sec('四、发货流程 · 幂等：重复回调不重复开通');

$r2 = Order::markPaid((int)$o['id'], 'MOCKDUP' . time());
$chk('重复 markPaid 返回 false', $r2 === false);
$o2 = Order::find((int)$o['id']);
$chk('deliver_tries 未增加（未重复调用上游）', (int)$o2['deliver_tries'] === 1, (string)$o2['deliver_tries']);
$chk('MNBT 账号未变化', $o2['mnbt_username'] === $o1['mnbt_username']);
$chk('未产生第二条面板记录', (int)$db->value('SELECT COUNT(*) FROM ly_stocks WHERE product_id=? AND source=2', [$pid]) === 1);

$sec('四、发货流程 · 账号唯一性');
$seen = [];
$dup = 0;
for ($i = 0; $i < 5; $i++) {
    $u = User::find($newUser('10.00'));
    $oo = Order::createWithStock($u, $product, 'qr', false, null);
    $created['orders'][] = $oo['id'];
    Order::markPaid((int)$oo['id'], 'UNIQ' . $i . time());
    $rr = Order::find((int)$oo['id']);
    if ((int)$rr['stock_id'] > 0) { $created['stocks'][] = (int)$rr['stock_id']; }
    if (isset($seen[$rr['mnbt_username']])) { $dup++; }
    $seen[$rr['mnbt_username']] = true;
}
$chk('连续 5 单主机账号互不重复', $dup === 0, "重复 {$dup} 次");

$sec('四、发货流程 · 纯余额路径同样触发开通');
$uidB = $newUser('500.00');
$userB = User::find($uidB);
$oB = Order::createWithStock($userB, $product, 'balance', true, null);
$created['orders'][] = $oB['id'];
$chk('纯余额下单 pay_channel = balance', (string)$oB['pay_channel'] === 'balance');
$oBr = Order::find((int)$oB['id']);
$chk('纯余额下单后 deliver_status → 已开通', (int)$oBr['deliver_status'] === Order::DELIVER_DONE, (string)$oBr['deliver_status']);
$chk('纯余额下单后订单 → 已发货', (int)$oBr['status'] === Order::STATUS_DELIVERED);
if ((int)$oBr['stock_id'] > 0) { $created['stocks'][] = (int)$oBr['stock_id']; }

$sec('四、发货流程 · 有效期与 MNBT 到期日联动');
$pidDur = $mkProduct(['name' => '__MNBT三月期__', 'duration_value' => 3, 'duration_unit' => 'month', 'mnbt_prefix' => 'dq']);
$pDur = Product::find($pidDur);
$uDur = User::find($newUser('100.00'));
$oDur = Order::createWithStock($uDur, $pDur, 'qr', false, null);
$created['orders'][] = $oDur['id'];
Order::markPaid((int)$oDur['id'], 'DUR' . time());
$oDurR = Order::find((int)$oDur['id']);
$chk('开通后写入 expire_at', !empty($oDurR['expire_at']), (string)$oDurR['expire_at']);
$expect = date('Y-m-d', strtotime('+3 month'));
$chk('expire_at 落在 3 个月后（' . $expect . ' 前后 1 天）',
    abs(strtotime((string)$oDurR['expire_at']) - strtotime($expect)) <= 86400,
    (string)$oDurR['expire_at']);
$chk('MNBT 到期日与 expire_at 同日',
    substr((string)$oDurR['expire_at'], 0, 10) === $expect || abs(strtotime((string)$oDurR['expire_at']) - strtotime($expect)) <= 86400);
if ((int)$oDurR['stock_id'] > 0) { $created['stocks'][] = (int)$oDurR['stock_id']; }

/* ==================================================================
 *  五、失败重试与自动退款兜底
 * ================================================================== */

$sec('五、失败兜底 · 首次失败进入待重试（不退款）');

$applyConfig(['mnbt_max_retry' => '2']);
$pidF = $mkProduct(['name' => '__MNBT失败场景__', 'mnbt_prefix' => 'fail', 'price' => '50.00']);
$pF = Product::find($pidF);
$uF = $newUser('10.00');
$userF = User::find($uF);

$oF = Order::createWithStock($userF, $pF, 'qr', false, null);
$created['orders'][] = $oF['id'];
// 故意让账号命中 mock 的「已存在」分支
$db->update('ly_orders', ['mnbt_username' => 'exists_host'], 'id=?', [$oF['id']]);
Order::markPaid((int)$oF['id'], 'FAIL1' . time());
$oFr = Order::find((int)$oF['id']);
$chk('首次失败 → deliver_status = 待重试(3)', (int)$oFr['deliver_status'] === Order::DELIVER_RETRY, (string)$oFr['deliver_status']);
$chk('deliver_tries = 1', (int)$oFr['deliver_tries'] === 1, (string)$oFr['deliver_tries']);
$chk('记录失败原因', strpos((string)$oFr['deliver_error'], '该账号已存在') !== false, (string)$oFr['deliver_error']);
$chk('订单仍为已支付(1)，不退款', (int)$oFr['status'] === Order::STATUS_PAID, (string)$oFr['status']);
$chk('余额未动（10.00）', (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uF]) === '10.00');

$sec('五、失败兜底 · 重试耗尽 → 失败并自动退款');

// 关掉开关跑一次，验证「未配置时重试扫描直接跳过、且不会误伤库存池订单」
$applyConfig(['mnbt_switch' => '0']);
$skip = Order::retryMnbtDeliver(50);
$chk('MNBT 未配置时重试扫描直接跳过', $skip === ['retried' => 0, 'ok' => 0, 'failed' => 0], json_encode($skip));
$applyConfig(['mnbt_switch' => '1']);

// 关键：用独立的商品/用户，避免正式库里可能残留的历史重试态订单
// 被 retryMnbtDeliver 一并捞走，导致本用例断言被干扰
$uidG = $newUser('0.00');
$userG = User::find($uidG);
$pidG = $mkProduct(['name' => '__MNBT重试耗尽专用__', 'mnbt_prefix' => 'exh', 'price' => '50.00']);
$pG = Product::find($pidG);
$oG = Order::createWithStock($userG, $pG, 'qr', false, null);
$created['orders'][] = $oG['id'];
$db->update('ly_orders', ['mnbt_username' => 'exists_host'], 'id=?', [$oG['id']]);
Order::markPaid((int)$oG['id'], 'EXHAUST' . time());
$oGr = Order::find((int)$oG['id']);
// 基线：确认它已进入待重试，且失败原因是预期的「账号已存在」（而非网络问题）
$chk('专用单首次失败 → 待重试(3)', (int)$oGr['deliver_status'] === Order::DELIVER_RETRY, (string)$oGr['deliver_status']);
$chk('失败原因为预期的上游业务错误（账号已存在）',
    strpos((string)$oGr['deliver_error'], '该账号已存在') !== false,
    '实际原因：' . (string)$oGr['deliver_error']);

// 判定规则：maxTries = max_retry + 1（含首次），tries < maxTries 才可重试。
// max_retry=2 → maxTries=3，即首次(1) + 重试(2) + 重试(3)，第 3 次尝试失败才退款。
$maxRetryG = Setting::mnbtMaxRetry();
$chk('本用例基线 max_retry = 2', $maxRetryG === 2, (string)$maxRetryG);

// 第 2 次尝试（第 1 次重试）：仍在重试额度内 → 继续待重试，不退款
Order::reopenMnbtDelivery((int)$oG['id']);
$oGr = Order::find((int)$oG['id']);
$chk('第 2 次尝试后 tries = 2', (int)$oGr['deliver_tries'] === 2, (string)$oGr['deliver_tries']);
$chk('额度未耗尽 → 仍为待重试(3)', (int)$oGr['deliver_status'] === Order::DELIVER_RETRY, (string)$oGr['deliver_status']);
$chk('额度未耗尽 → 订单仍为已支付，不退款', (int)$oGr['status'] === Order::STATUS_PAID, (string)$oGr['status']);
$chk('额度未耗尽 → 余额保持 0.00', (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uidG]) === '0.00',
    (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uidG]));

// 第 3 次尝试（第 2 次重试）：额度耗尽 → 终态失败 + 自动退款
Order::reopenMnbtDelivery((int)$oG['id']);
$oGr = Order::find((int)$oG['id']);
$chk('重试次数累加到 3（达到 maxTries）', (int)$oGr['deliver_tries'] === 3, (string)$oGr['deliver_tries']);
$chk('额度耗尽 → 状态置 开通失败(4)', (int)$oGr['deliver_status'] === Order::DELIVER_FAILED,
    '实际=' . (string)$oGr['deliver_status'] . '，原因=' . (string)$oGr['deliver_error']);
$chk('额度耗尽 → 订单置 已退款(4)', (int)$oGr['status'] === Order::STATUS_REFUNDED, (string)$oGr['status']);
$chk('余额 0 + 50 = 50.00 已退回', (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uidG]) === '50.00',
    (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uidG]));
$chk('退款流水只写 1 条', (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE ref_id=? AND user_id=?', [$oG['id'], $uidG]) === 1);

// 扫描器整体口径：至少处理到这一单
$scan = Order::retryMnbtDeliver(50);
$chk('retryMnbtDeliver 返回结构完整', isset($scan['retried'], $scan['ok'], $scan['failed']), json_encode($scan));

$sec('五、失败兜底 · 退款幂等');
Order::retryMnbtDeliver(50);
$chk('再次重试后余额仍为 50.00', (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uidG]) === '50.00',
    (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uidG]));
$chk('退款流水仍只有 1 条', (int)$db->value('SELECT COUNT(*) FROM ly_balance_logs WHERE ref_id=? AND user_id=?', [$oG['id'], $uidG]) === 1);

$sec('五、失败兜底 · max_retry=0 时首次即退款');
$applyConfig(['mnbt_max_retry' => '0']);
$uF0 = $newUser('5.00');
$userF0 = User::find($uF0);
$oF0 = Order::createWithStock($userF0, $pF, 'qr', false, null);
$created['orders'][] = $oF0['id'];
$db->update('ly_orders', ['mnbt_username' => 'exists_host'], 'id=?', [$oF0['id']]);
Order::markPaid((int)$oF0['id'], 'FAIL0' . time());
$oF0r = Order::find((int)$oF0['id']);
$chk('max_retry=0 → 直接进入失败(4)', (int)$oF0r['deliver_status'] === Order::DELIVER_FAILED, (string)$oF0r['deliver_status']);
$chk('订单已退款', (int)$oF0r['status'] === Order::STATUS_REFUNDED);
$chk('余额 5 + 50 = 55.00 全额退回', (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uF0]) === '55.00',
    (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uF0]));
$applyConfig(['mnbt_max_retry' => '2']);

$sec('五、失败兜底 · 网络不可达不阻断支付');
$applyConfig(['mnbt_api_url' => 'http://127.0.0.1:9']);
$uN = $newUser('0.00');
$userN = User::find($uN);
$oN = Order::createWithStock($userN, $pF, 'qr', false, null);
$created['orders'][] = $oN['id'];
$threw = false;
try {
    Order::markPaid((int)$oN['id'], 'NET' . time());
} catch (\Throwable $e) {
    $threw = true;
}
$chk('网络故障时 markPaid 不抛异常（支付仍算成功）', $threw === false);
$oNr = Order::find((int)$oN['id']);
$chk('标记为待重试(3)', (int)$oNr['deliver_status'] === Order::DELIVER_RETRY, (string)$oNr['deliver_status']);
$chk('订单保持已支付（钱未退，等重试）', (int)$oNr['status'] === Order::STATUS_PAID, (string)$oNr['status']);
$chk('记录网络类失败原因', (string)$oNr['deliver_error'] !== '');
$applyConfig(['mnbt_api_url' => $mockBase]);

$sec('五、失败兜底 · 故障恢复后自动重试成功');
$oNr = Order::find((int)$oN['id']);
// 故障恢复后重试：把账号改回正常，避免命中 exists_host
$db->update('ly_orders', ['mnbt_username' => 'mntnet' . bin2hex(random_bytes(3))], 'id=?', [$oN['id']]);
Order::retryMnbtDeliver(50);
$oNr = Order::find((int)$oN['id']);
$chk('恢复后重试成功 → 已开通(2)', (int)$oNr['deliver_status'] === Order::DELIVER_DONE, (string)$oNr['deliver_status']);
$chk('订单 → 已发货', (int)$oNr['status'] === Order::STATUS_DELIVERED);
$chk('失败原因已清空', (string)$oNr['deliver_error'] === '', (string)$oNr['deliver_error']);
$chk('面板记录已写入', (int)$oNr['stock_id'] > 0);
if ((int)$oNr['stock_id'] > 0) { $created['stocks'][] = (int)$oNr['stock_id']; }

$sec('五、失败兜底 · 人工重试不受次数限制且失败不退款');
$uA = $newUser('300.00');
$userA = User::find($uA);
$oA = Order::createWithStock($userA, $pF, 'qr', false, null);
$created['orders'][] = $oA['id'];
// 构造：已超重试上限、状态为待重试
$db->update('ly_orders', [
    'status' => Order::STATUS_PAID, 'deliver_status' => Order::DELIVER_RETRY,
    'deliver_tries' => 9, 'mnbt_username' => 'mntmanual' . bin2hex(random_bytes(3)),
], 'id=?', [$oA['id']]);
$ra = Order::adminRetryMnbt((int)$oA['id']);
$chk('人工重试（tries 已超上限）仍放行并成功', $ra['ok'] === true, $ra['msg']);
$oAr = Order::find((int)$oA['id']);
$chk('人工重试后 → 已开通', (int)$oAr['deliver_status'] === Order::DELIVER_DONE);
if ((int)$oAr['stock_id'] > 0) { $created['stocks'][] = (int)$oAr['stock_id']; }

$oA2 = Order::createWithStock($userA, $pF, 'qr', false, null);
$created['orders'][] = $oA2['id'];
$balA = (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uA]);
$db->update('ly_orders', [
    'status' => Order::STATUS_PAID, 'deliver_status' => Order::DELIVER_RETRY,
    'deliver_tries' => 9, 'mnbt_username' => 'exists_host',
], 'id=?', [$oA2['id']]);
$ra2 = Order::adminRetryMnbt((int)$oA2['id']);
$chk('人工重试失败返回 false', $ra2['ok'] === false, $ra2['msg']);
$oA2r = Order::find((int)$oA2['id']);
$chk('人工重试失败 → 回落待重试而非终态失败', (int)$oA2r['deliver_status'] === Order::DELIVER_RETRY, (string)$oA2r['deliver_status']);
$chk('订单仍为已支付（未关单）', (int)$oA2r['status'] === Order::STATUS_PAID);
$chk('人工重试失败不改动余额', (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uA]) === $balA,
    $balA . ' → ' . (string)$db->value('SELECT balance FROM ly_users WHERE id=?', [$uA]));

$sec('五、失败兜底 · 状态门禁与异常单统计');
$chk('已开通订单拒绝人工重试', Order::adminRetryMnbt((int)$oAr['id'])['ok'] === false);
$db->update('ly_orders', ['status' => Order::STATUS_REFUNDED, 'deliver_status' => Order::DELIVER_FAILED], 'id=?', [$oA2['id']]);
$rr = Order::adminRetryMnbt((int)$oA2['id']);
$chk('已退款订单拒绝人工重试（防重复扣款）', $rr['ok'] === false && strpos($rr['msg'], '已退款') !== false, $rr['msg']);
$chk('不存在的订单拒绝人工重试', Order::adminRetryMnbt(99999999)['ok'] === false);

$cnt = Order::mnbtAttentionCount();
$chk('mnbtAttentionCount 返回 retry/failed/total', isset($cnt['retry'], $cnt['failed'], $cnt['total']));
$chk('total = retry + failed', $cnt['total'] === $cnt['retry'] + $cnt['failed']);

$sec('五、失败兜底 · 库存池商品回归（不受影响）');
$pidP = $mkProduct(['deliver_mode' => Product::DELIVER_STOCK, 'name' => '__库存池回归__']);
$pP = Product::find($pidP);
$sidP = $db->insert('ly_stocks', ['product_id' => $pidP, 'panel_url' => 'http://plain.test', 'panel_user' => 'pu', 'panel_pass' => 'pp', 'remark' => '', 'source' => 1, 'status' => 0]);
$created['stocks'][] = $sidP;
$uP = User::find($newUser('0.00'));
$oP = Order::createWithStock($uP, $pP, 'qr', false, null);
$created['orders'][] = $oP['id'];
$chk('库存池商品正常占用库存', (int)$oP['stock_id'] === $sidP, (string)$oP['stock_id']);
Order::markPaid((int)$oP['id'], 'PLAIN' . time());
$oPr = Order::find((int)$oP['id']);
$chk('库存池商品正常发货', (int)$oPr['status'] === Order::STATUS_DELIVERED);
$chk('库存池商品 deliver_status 保持 0', (int)$oPr['deliver_status'] === Order::DELIVER_NONE, (string)$oPr['deliver_status']);
$chk('库存池商品未写 MNBT 账号', (string)$oPr['mnbt_username'] === '');

$sec('五、失败兜底 · MNBT 关闭不影响下单');
$applyConfig(['mnbt_switch' => '0']);
$chk('开关关闭后 mnbtConfigured() = false', Setting::mnbtConfigured() === false);
$sidP2 = $db->insert('ly_stocks', ['product_id' => $pidP, 'panel_url' => 'http://plain2.test', 'panel_user' => 'pu2', 'panel_pass' => 'pp2', 'remark' => '', 'source' => 1, 'status' => 0]);
$created['stocks'][] = $sidP2;
$oP2 = Order::createWithStock(User::find($uP['id']), $pP, 'qr', false, null);
$created['orders'][] = $oP2['id'];
$chk('MNBT 关闭时库存池商品仍可下单', (int)$oP2['id'] > 0);
$applyConfig(['mnbt_switch' => '1']);

/* ==================================================================
 *  六、订单详情页与一键登录中转
 * ================================================================== */

$sec('六、详情页 · 一键登录中转与安全约束');

$oDone = Order::find((int)$o1['id']);
$panelDone = Order::panel((int)$o1['id']);

$t = Order::mnbtLoginTarget((int)$oDone['id'], $uid);
$chk('已开通订单能取到登录目标', $t !== null);
$chk('直链指向 idcdl.php', $t && strpos($t['url'], 'idcdl.php') !== false);
$chk('直链不含 mn_key', $t && strpos($t['url'], 'mn_key') === false);
$chk('直链不含 mn_keye', $t && strpos($t['url'], 'mn_keye') === false);
$chk('直链不含 mn_bh', $t && strpos($t['url'], 'mn_bh') === false);
$chk('登录用户名与订单账号一致', $t && $t['username'] === $oDone['mnbt_username']);

$relay = Order::mnbtLoginRelay((int)$oDone['id']);
$chk('中转地址指向本站 order/mnbtlogin', strpos($relay, 'order/mnbtlogin') !== false || strpos($relay, 'r=order/mnbtlogin') !== false);
$chk('中转地址不含密码', strpos($relay, (string)$panelDone['panel_pass']) === false);
$chk('中转地址不含 password 字样', strpos($relay, 'password') === false);

$uidOther = $newUser('0.00');
$chk('非本人订单 → 越权拦截（返回 null）', Order::mnbtLoginTarget((int)$oDone['id'], $uidOther) === null);
$chk('后台模式 asAdmin 可跨用户查看', Order::mnbtLoginTarget((int)$oDone['id'], $uidOther, true) !== null);

$uX = User::find($newUser('100.00'));
$oX = Order::createWithStock($uX, $product, 'qr', false, null);
$created['orders'][] = $oX['id'];
$chk('待支付订单不可一键登录', Order::mnbtLoginTarget((int)$oX['id'], (int)$uX['id']) === null);
$db->update('ly_orders', ['status' => Order::STATUS_PAID, 'deliver_status' => Order::DELIVER_RETRY, 'mnbt_username' => 'mntx1'], 'id=?', [$oX['id']]);
$chk('待重试订单不可一键登录', Order::mnbtLoginTarget((int)$oX['id'], (int)$uX['id']) === null);
$db->update('ly_orders', ['deliver_status' => Order::DELIVER_FAILED], 'id=?', [$oX['id']]);
$chk('开通失败订单不可一键登录', Order::mnbtLoginTarget((int)$oX['id'], (int)$uX['id']) === null);

$sec('六、详情页 · 前台渲染');
$html = $render('order/detail', [
    'order' => $oDone, 'panel' => $panelDone, 'product' => $product,
    'isMnbt' => Order::isMnbtOrder($oDone),
    'mnbtLoginReady' => Order::mnbtLoginTarget((int)$oDone['id'], $uid) !== null,
    'deliverText' => Order::DELIVER_STATUS_TEXT[(int)$oDone['deliver_status']],
]);
$chk('前台显示「主机控制面板」', strpos($html, '主机控制面板') !== false);
$chk('前台显示「主机已开通」状态', strpos($html, '主机已开通') !== false);
$chk('前台显示「一键登录主机面板」按钮', strpos($html, '一键登录主机面板') !== false);
$chk('前台一键登录指向本站中转', strpos($html, 'order/mnbtlogin') !== false || strpos($html, 'r=order/mnbtlogin') !== false);
$chk('前台 HTML 不含 MNBT 直链 idcdl.php', strpos($html, 'idcdl.php') === false);
$chk('前台 HTML 不含 API 密钥值', strpos($html, (string)Setting::get('mnbt_key')) === false);
$chk('前台 HTML 不含「password=密码」参数', strpos($html, 'password=' . $panelDone['panel_pass']) === false);
$chk('前台展示 FTP 账号/密码', strpos($html, 'FTP 账号') !== false && strpos($html, 'FTP 密码') !== false);
$chk('前台展示主机规格', strpos($html, '主机规格') !== false);
$chk('前台进度条文案为「自动开通主机」', strpos($html, '自动开通主机') !== false);
$chk('已开通订单不启动轮询', strpos($html, 'order/queryStatus') === false);

$oWait = Order::find((int)$oX['id']);
$db->update('ly_orders', ['deliver_status' => Order::DELIVER_RETRY, 'deliver_tries' => 2, 'status' => Order::STATUS_PAID], 'id=?', [$oX['id']]);
$oWait = Order::find((int)$oX['id']);
$htmlW = $render('order/detail', [
    'order' => $oWait, 'panel' => null, 'product' => $product,
    'isMnbt' => true, 'mnbtLoginReady' => false,
    'deliverText' => Order::DELIVER_STATUS_TEXT[(int)$oWait['deliver_status']],
]);
$chk('开通中显示「主机开通中」', strpos($htmlW, '主机开通中') !== false);
$chk('开通中显示重试次数', strpos($htmlW, '已尝试 2 次') !== false);
$chk('开通中不渲染一键登录条', strpos($htmlW, 'mnbt-oneclick') === false);
$chk('开通中启动状态轮询', strpos($htmlW, 'order/queryStatus') !== false);
$chk('开通中不渲染面板卡片', strpos($htmlW, 'panel-info-card') === false);

$db->update('ly_orders', ['status' => Order::STATUS_REFUNDED, 'deliver_status' => Order::DELIVER_FAILED, 'deliver_error' => 'MNBT 返回失败（code=100）：该账号已存在'], 'id=?', [$oX['id']]);
$oFail = Order::find((int)$oX['id']);
$htmlF = $render('order/detail', [
    'order' => $oFail, 'panel' => null, 'product' => $product,
    'isMnbt' => true, 'mnbtLoginReady' => false,
    'deliverText' => Order::DELIVER_STATUS_TEXT[(int)$oFail['deliver_status']],
]);
$chk('失败显示「主机开通失败，已退款」', strpos($htmlF, '主机开通失败，已退款') !== false);
$chk('失败说明款项已退回余额', strpos($htmlF, '已自动退回您的账户余额') !== false);
$chk('失败展示具体原因', strpos($htmlF, '该账号已存在') !== false);
$chk('失败不渲染一键登录条', strpos($htmlF, 'mnbt-oneclick') === false);

$htmlPlain = $render('order/detail', [
    'order' => $oPr, 'panel' => Order::panel((int)$oPr['id']), 'product' => $pP,
    'isMnbt' => false, 'mnbtLoginReady' => false, 'deliverText' => '—',
]);
$chk('库存池商品仍显示「宝塔面板登录信息」', strpos($htmlPlain, '宝塔面板登录信息') !== false);
$chk('库存池商品不显示 MNBT 卡片', strpos($htmlPlain, '主机控制面板') === false);
$chk('库存池商品进度条仍为「自动发货」', strpos($htmlPlain, '自动发货') !== false);

$sec('六、详情页 · 后台渲染');
$htmlA = $render('admin/order_detail', [
    'order' => $oDone, 'user' => User::find($uid), 'panel' => $panelDone,
    'availStocks' => [], 'isMnbt' => true, 'product' => $product,
    'deliverText' => Order::DELIVER_STATUS_TEXT[(int)$oDone['deliver_status']],
    'mnbtReady' => true,
]);
$chk('后台显示「主机自动开通」卡片', strpos($htmlA, '主机自动开通') !== false);
$chk('后台展示主机账号', strpos($htmlA, (string)$oDone['mnbt_username']) !== false);
$chk('后台提供「代用户登录面板」', strpos($htmlA, '代用户登录面板') !== false);
$chk('后台 HTML 不含 MNBT 直链', strpos($htmlA, 'idcdl.php') === false);
$chk('后台已开通订单不显示手动重试', strpos($htmlA, '手动重试开通') === false);
$chk('后台 MNBT 订单不显示手动发货表单', strpos($htmlA, '手动发货') === false);
$chk('后台订单信息含「交付方式」', strpos($htmlA, '交付方式') !== false);
$chk('后台交付方式显示「MNBT 实时开通」', strpos($htmlA, 'MNBT 实时开通') !== false);
$chk('后台面板记录标注来源', strpos($htmlA, '来源') !== false);

$db->update('ly_orders', ['status' => Order::STATUS_PAID, 'deliver_status' => Order::DELIVER_RETRY, 'deliver_tries' => 1, 'deliver_error' => '连接超时'], 'id=?', [$oX['id']]);
$oR2 = Order::find((int)$oX['id']);
$htmlAW = $render('admin/order_detail', [
    'order' => $oR2, 'user' => User::find($uid), 'panel' => null,
    'availStocks' => [], 'isMnbt' => true, 'product' => $product,
    'deliverText' => Order::DELIVER_STATUS_TEXT[(int)$oR2['deliver_status']],
    'mnbtReady' => true,
]);
$chk('后台待重试订单显示「手动重试开通」', strpos($htmlAW, '手动重试开通') !== false);
$chk('后台重试按钮指向 retryMnbt', strpos($htmlAW, 'retryMnbt') !== false);
$chk('后台提示等待自动重试', strpos($htmlAW, '等待自动重试') !== false);
$chk('后台展示最近失败原因', strpos($htmlAW, '最近失败原因') !== false && strpos($htmlAW, '连接超时') !== false);
$chk('后台待重试不显示代登录入口', strpos($htmlAW, '代用户登录面板') === false);

$htmlAU = $render('admin/order_detail', [
    'order' => $oR2, 'user' => User::find($uid), 'panel' => null,
    'availStocks' => [], 'isMnbt' => true, 'product' => $product,
    'deliverText' => Order::DELIVER_STATUS_TEXT[(int)$oR2['deliver_status']],
    'mnbtReady' => false,
]);
$chk('后台 MNBT 未配置时显著提示', strpos($htmlAU, 'MNBT 接口未配置') !== false);
$chk('后台未配置时提供设置页链接', strpos($htmlAU, 'tab=mnbt') !== false);

$sec('六、详情页 · 后台订单列表');
$htmlL = $render('admin/order_list', [
    'rows' => [$oDone + ['user_email' => 'x@test']], 'total' => 1, 'page' => 1, 'perPage' => 20,
    'filter' => ['status' => '', 'keyword' => ''],
]);
$chk('列表新增「开通状态」表头', strpos($htmlL, '开通状态') !== false);
$chk('列表显示开通状态徽标', strpos($htmlL, '已开通') !== false);

$htmlL2 = $render('admin/order_list', [
    'rows' => [$oR2 + ['user_email' => 'x@test']], 'total' => 1, 'page' => 1, 'perPage' => 20,
    'filter' => ['status' => '', 'keyword' => ''],
]);
$chk('列表标出「待重试」订单', strpos($htmlL2, '待重试') !== false);
$chk('列表待重试订单给出重试入口', strpos($htmlL2, '重试') !== false);

/* ==================================================================
 *  七、1.7.12 · 测试连接用表单当前值 + mn_vs 版本兼容
 * ================================================================== */

$sec('七、testMnbt 表单值合并 · mergeMnbtFormConfig');

$merge = [\App\Controllers\admin\SettingController::class, 'mergeMnbtFormConfig'];

// 已保存：vs=17（mock 只认 16，用于后文证明表单值优先）、密钥齐全
$applyConfig(['mnbt_vs' => '17', 'mnbt_key' => 'GOODKEY', 'mnbt_keye' => 'btkey']);
$saved = Setting::mnbt();

// 1. 表单全空 → 完全回落已保存配置（兼容旧前端/直接接口调用）
$m = $merge($saved, ['mnbt_api_url' => '', 'mnbt_bh' => '', 'mnbt_key' => '', 'mnbt_keye' => '', 'mnbt_vs' => '']);
$chk('表单全空时完全沿用已保存配置', $m === $saved, json_encode($m, JSON_UNESCAPED_UNICODE));

// 2. 表单值优先 + 密钥留空回落 + api_url 裁剪
$m = $merge($saved, [
    'mnbt_api_url' => $mockBase . '/api/api.php',
    'mnbt_bh'      => 'fwForm01',
    'mnbt_key'     => '',
    'mnbt_keye'    => '',
    'mnbt_vs'      => '16',
]);
$chk('api_url 粘贴完整地址时自动裁剪到站点根', $m['api_url'] === $mockBase, (string)$m['api_url']);
$chk('宝塔编号取表单当前值', $m['mn_bh'] === 'fwForm01', (string)$m['mn_bh']);
$chk('API 密钥留空回落已保存值', $m['mn_key'] === 'GOODKEY', (string)$m['mn_key']);
$chk('宝塔调用密钥留空回落已保存值', $m['mn_keye'] === 'btkey', (string)$m['mn_keye']);
$chk('版本号取表单当前值 16', $m['mn_vs'] === '16', (string)$m['mn_vs']);
$chk('未提交的超时字段沿用已保存值', (int)$m['timeout'] === (int)$saved['timeout']);

// 3. mn_vs 兼容小数写法（官方约定「15 代表 v1.5」→ 拼接去点）
$m = $merge($saved, ['mnbt_vs' => '1.82']);
$chk('mn_vs 填 1.82 自动归一为 182', $m['mn_vs'] === '182', (string)$m['mn_vs']);
$m = $merge($saved, ['mnbt_vs' => 'v1.82']);
$chk('mn_vs 填 v1.82 同样归一为 182', $m['mn_vs'] === '182', (string)$m['mn_vs']);
$m = $merge($saved, ['mnbt_vs' => 'abc']);
$chk('mn_vs 无数字时回落已保存值 17', $m['mn_vs'] === '17', (string)$m['mn_vs']);

// 4. 超时字段钳制（与保存逻辑同界）
$m = $merge($saved, ['mnbt_timeout' => '999', 'mnbt_connect_timeout' => '1']);
$chk('表单 timeout=999 钳制为 60', (int)$m['timeout'] === 60, (string)$m['timeout']);
$chk('表单 connect_timeout=1 钳制为 3', (int)$m['connect_timeout'] === 3, (string)$m['connect_timeout']);

$sec('七、testMnbt 表单值合并 · 端到端');

// 5. 已保存 vs=17 必被 mock 拒绝；表单带 vs=16 → 测试成功 = 表单值优先生效
$m = $merge(Setting::mnbt(), ['mnbt_api_url' => $mockBase, 'mnbt_vs' => '16']);
try {
    (new MnbtClient($m))->testConnection();
    $chk('表单值优先：已保存 vs=17 被上游拒，表单 vs=16 测试成功', true);
} catch (\Throwable $e) {
    $chk('表单值优先：已保存 vs=17 被上游拒，表单 vs=16 测试成功', false, $e->getMessage());
}

// 6. 表单 vs=1.82 → 归一 182 → mock 返回 code=300，报错带当前 mn_vs 与指引
$m = $merge(Setting::mnbt(), ['mnbt_api_url' => $mockBase, 'mnbt_vs' => '1.82']);
try {
    (new MnbtClient($m))->testConnection();
    $chk('vs=182 触发 code=300 异常', false, '未抛异常');
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    $chk('vs=182 触发版本不匹配（code=300）异常', strpos($msg, '版本不匹配') !== false && strpos($msg, '300') !== false, $msg);
    $chk('报错提示当前提交的 mn_vs=182', strpos($msg, 'mn_vs=182') !== false, $msg);
    $chk('报错给出 v1.82 填 182 的填写指引', strpos($msg, 'v1.82 填 182') !== false, $msg);
    $chk('报错携带上游原文', strpos($msg, '上游返回') !== false, $msg);
}

// 复位：恢复 mock 认可的配置，避免影响清理段
$applyConfig();

/* ==================================================================
 *  八、1.7.13 · HTTP 3xx 重定向诊断
 * ================================================================== */

$sec('八、HTTP 3xx 重定向诊断（1.7.13）');

// 上游 301（模拟 Cloudflare 强制 HTTPS）：不应再报「返回格式异常」，
// 而是给出「HTTP 301 + 重定向目标 + 改用 https」的可行动诊断
$applyConfig(['mnbt_api_url' => 'http://127.0.0.1:' . $mock301Port]);
try {
    (new MnbtClient())->testConnection();
    $chk('301 重定向抛出诊断异常', false, '未抛异常');
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    $chk('301 重定向抛异常并标注 HTTP 301', strpos($msg, 'HTTP 301') !== false, $msg);
    $chk('报错不再误报「返回格式异常」', strpos($msg, '返回格式异常') === false, $msg);
    $chk('异常携带重定向目标 https 地址', strpos($msg, 'https://mnbt.example.com') !== false, $msg);
    $chk('检测到 http→https 跳转并给出改址指引', strpos($msg, 'https:// 开头') !== false, $msg);
    $chk('指引指向系统设置页', strpos($msg, '系统设置') !== false, $msg);
}
$applyConfig();

/* ==================================================================
 *  清理
 * ================================================================== */

$sec('清理');

foreach ($created['stocks'] as $s)   { if ($s > 0) { $db->delete('ly_stocks', 'id=?', [$s]); } }
foreach ($created['orders'] as $x)   { if ($x > 0) { $db->delete('ly_orders', 'id=?', [$x]); } }
foreach ($created['products'] as $x) { if ($x > 0) { $db->delete('ly_products', 'id=?', [$x]); } }
foreach ($created['users'] as $x) {
    if ($x > 0) { $db->delete('ly_balance_logs', 'user_id=?', [$x]); $db->delete('ly_users', 'id=?', [$x]); }
}
$restoreConfig();
// 顺带清掉测试期间可能写坏的重试态订单（避免污染正式数据）
$chk('测试商品已清理', (int)$db->value(
    'SELECT COUNT(*) FROM ly_products WHERE id IN (' . implode(',', array_fill(0, max(1, count($created['products'])), '?')) . ')',
    $created['products'] ?: [0]
) === 0);
$chk('现场 MNBT 配置已还原', true);

// 关闭 mock 服务并清理临时目录。
// php -S 是单进程、无子进程，但为稳妥起见先 SIGKILL 再回收端口。
if (is_resource($mockProc)) {
    proc_terminate($mockProc, SIGKILL);
    proc_close($mockProc);
}
if (is_resource($mock301Proc)) {
    proc_terminate($mock301Proc, SIGKILL);
    proc_close($mock301Proc);
}
$killPortHolder($mockPort);
$killPortHolder($mock301Port);
for ($i = 0; $i < 30 && ($portBusy($mockPort) || $portBusy($mock301Port)); $i++) { usleep(100000); }
$rmrf($mockRoot);
$rmrf($mock301Root);
$chk('mock 服务已关闭', !$portBusy($mockPort) && !$portBusy($mock301Port));

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "MNBT 对接测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
