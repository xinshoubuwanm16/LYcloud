<?php
/**
 * 双主题测试（1.7.11 新增）
 *
 * 覆盖：
 *   - 色板完整性：默认樱花粉 :root 与 html[data-theme="bluecat"] 两套
 *     变量块均存在，关键变量（品牌色 / 底色 / 阴影 / 插画 / 光斑）无遗漏
 *   - 主题隔离：蓝白块的变量键集合应为默认块的超集（切换后无「变量掉空」）
 *   - 蓝白基调：主色为蓝色系（B 通道显著高于 R）、底色为冷白
 *   - 插画变量化：6 处插画引用已改为 var(--img-*)，无硬编码旧路径残留
 *   - 素材存在：蓝白 6 张 WebP 全部存在且体积合理（>8KB 视为有效图）；
 *     旧樱花插画保留（默认主题不受影响）
 *   - 前台渲染：<html> 无初始 data-theme（默认樱花粉）、切换按钮存在、
 *     内联预设脚本（防闪烁）在 <head> 且早于 body、两套图标节点齐全
 *   - 切换逻辑：localStorage 键名 ly_theme、两值白名单、点击切换、
 *     CSS 中按 data-theme 控制图标显隐
 *   - 静态资源：app.css / 蓝白插画 HTTP 200（防止路由把静态资源吃掉）
 *   - 后台隔离：后台样式不引用蓝白插画（访客偏好只作用于前台）
 *   - 回归：默认主题下 CSS 仍完整可用、既有页面无 PHP 报错
 *
 * 用法：LY_TEST_BASE=http://127.0.0.1:8099 php tests/theme_test.php
 * 前置：站点服务已启动（php -S 127.0.0.1:8099 -t 项目根）。
 */

require __DIR__ . '/../app/bootstrap.php';

$BASE = getenv('LY_TEST_BASE') ?: 'http://127.0.0.1:8099';
$ROOT = LY_ROOT;

$pass = 0;
$fail = 0;

function group(string $t): void
{
    echo PHP_EOL . '[' . $t . ']' . PHP_EOL;
}

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo '  [PASS] ' . $name . PHP_EOL;
    } else {
        $fail++;
        echo '  [FAIL] ' . $name . ($extra !== '' ? '  (' . $extra . ')' : '') . PHP_EOL;
    }
}

function httpGetTheme(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

// ============================================================
// 0. CSS 解析辅助
// ============================================================

$cssFile = $ROOT . '/assets/css/app.css';
$css = is_file($cssFile) ? (string)file_get_contents($cssFile) : '';

/** 抽取指定选择器的声明块内容 */
$blockOf = function (string $selector) use ($css): string {
    // 选择器需为精确块首（如 :root { 或 html[data-theme="bluecat"] {）
    $q = preg_quote($selector, '/');
    if (!preg_match('/' . $q . '\s*\{([^}]*)\}/s', $css, $m)) {
        return '';
    }
    return $m[1];
};

/** 从声明块解析变量 map */
$varsOf = function (string $block): array {
    $out = [];
    if (preg_match_all('/(--[a-z0-9\-]+)\s*:\s*([^;]+);/i', $block, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $out[$row[1]] = trim($row[2]);
        }
    }
    return $out;
};

// ============================================================
// 1. 色板结构与完整性
// ============================================================

group('1. 色板结构与完整性');

check('app.css 存在且非空', $css !== '' && strlen($css) > 50000,
    'len=' . strlen($css));

$rootBlock   = $blockOf(':root');
$bluecatBlock = $blockOf('html[data-theme="bluecat"]');
$rootVars    = $varsOf($rootBlock);
$bluecatVars = $varsOf($bluecatBlock);

check('默认 :root 变量块已解析', count($rootVars) > 20, 'n=' . count($rootVars));
check('蓝白主题变量块已解析（html[data-theme="bluecat"]）',
    count($bluecatVars) > 20, 'n=' . count($bluecatVars));

// 关键变量：切换主题后必须都有定义，否则会出现「某些元素仍用粉」的断裂
$mustHave = [
    '--bg', '--bg-soft', '--bg-pink', '--card', '--border',
    '--text', '--text-sub', '--text-dim',
    '--primary', '--primary-dark', '--primary-deep', '--primary-soft', '--primary-ink',
    '--success', '--warning', '--danger', '--info',
    '--shadow-sm', '--shadow', '--shadow-lg', '--shadow-glow',
    '--img-hero', '--img-pd', '--img-auth', '--img-empty', '--img-steps', '--img-balance',
    '--glow-1', '--glow-2', '--glow-3',
];
$missRoot = $missBlue = [];
foreach ($mustHave as $v) {
    if (!isset($rootVars[$v]))    { $missRoot[] = $v; }
    if (!isset($bluecatVars[$v])) { $missBlue[] = $v; }
}
check('默认主题含全部关键变量（' . count($mustHave) . ' 项）',
    !$missRoot, implode(',', $missRoot));
check('蓝白主题含全部关键变量（切换无「变量掉空」）',
    !$missBlue, implode(',', $missBlue));

// 主题隔离：色彩 / 阴影 / 插画 / 光斑类变量必须被蓝白块全部覆盖，
// 否则会出现「某些元素仍显示粉色」的断裂。
// （--radius-*、--sans、--mono 属结构性变量，两主题共用，不在覆盖要求内）
$themeScoped = array_values(array_filter(array_keys($rootVars), function ($k) {
    return !in_array($k, ['--radius', '--radius-sm', '--radius-xs', '--radius-pill', '--sans', '--mono'], true);
}));
$notCovered = [];
foreach ($themeScoped as $k) {
    if (!isset($bluecatVars[$k])) { $notCovered[] = $k; }
}
check('蓝白主题覆盖全部主题相关性变量（色彩/阴影/插画/光斑，共 ' . count($themeScoped) . ' 项）',
    !$notCovered, '未覆盖: ' . implode(',', array_slice($notCovered, 0, 6)));

// 结构性变量（圆角/字体）蓝白块可不重复声明——未声明时自动继承 :root，
// 这正是我们想要的（切换主题不改变圆角与字体，仅换色换图）。
$structOk = true;
foreach (['--radius', '--radius-sm', '--radius-pill', '--sans', '--mono'] as $k) {
    $inBlue = $bluecatVars[$k] ?? null;
    // 未在蓝白块声明 = 继承 :root（正确）；若声明则必须与 :root 等值
    if ($inBlue !== null && $inBlue !== ($rootVars[$k] ?? null)) { $structOk = false; }
}
check('结构性变量未被主题改写（圆角/字体两主题一致，切换无布局跳动）', $structOk);

// ============================================================
// 2. 蓝白基调正确性
// ============================================================

group('2. 蓝白基调正确性');

/** #rrggbb → [r,g,b] */
$hex2rgb = function (string $hex): ?array {
    if (preg_match('/^#([0-9a-f]{6})$/i', trim($hex), $m)) {
        return [
            hexdec(substr($m[1], 0, 2)),
            hexdec(substr($m[1], 2, 2)),
            hexdec(substr($m[1], 4, 2)),
        ];
    }
    return null;
};

$pb = $hex2rgb($bluecatVars['--primary'] ?? '');
check('蓝白主色为合法 HEX', $pb !== null, $bluecatVars['--primary'] ?? '(无)');
check('蓝白主色属蓝色系（B > R 且 B > G 且 B 高亮）',
    $pb !== null && $pb[2] > $pb[0] && $pb[2] >= $pb[1] && $pb[2] > 180,
    $pb ? "rgb({$pb[0]},{$pb[1]},{$pb[2]})" : '');

$bgB = $hex2rgb($bluecatVars['--bg'] ?? '');
check('蓝白底色为冷白（B ≥ R，且亮度 > 240）',
    $bgB !== null && $bgB[2] >= $bgB[0] && min($bgB) > 240,
    $bgB ? "rgb({$bgB[0]},{$bgB[1]},{$bgB[2]})" : '');

// 默认主题仍应是粉色系（回归：没有被误改）
$pr = $hex2rgb($rootVars['--primary'] ?? '');
check('默认主色仍为樱花粉（R > B）',
    $pr !== null && $pr[0] > $pr[2],
    $pr ? "rgb({$pr[0]},{$pr[1]},{$pr[2]})" : '');

check('两主题主色不同（切换确实换色）',
    ($rootVars['--primary'] ?? '') !== ($bluecatVars['--primary'] ?? ''));
check('两主题插画路径不同（切换确实换图）',
    ($rootVars['--img-hero'] ?? '') !== ($bluecatVars['--img-hero'] ?? ''));

// ============================================================
// 3. 插画引用变量化
// ============================================================

group('3. 插画引用变量化');

// body 与各伪元素应通过 var(--img-*) / var(--glow-*) 引用，不再硬编码
$varUse = [
    'var(--img-hero)'   => 'Hero 主视觉',
    'var(--img-pd)'     => '商品详情底纹',
    'var(--img-auth)'   => '登录页 chibi',
    'var(--img-empty)'  => '空状态',
    'var(--img-steps)'  => '流程区块底纹',
    'var(--img-balance)' => '余额页装饰',
];
foreach ($varUse as $needle => $label) {
    check("插画已变量化：{$label}（{$needle}）",
        substr_count($css, $needle) >= 1, 'count=' . substr_count($css, $needle));
}

$missVar = [];
foreach ($varUse as $needle => $label) {
    if (substr_count($css, $needle) < 1) { $missVar[] = $label; }
}
check('全部 6 处插画引用均走变量', !$missVar, implode(',', $missVar));

check('全站背景光斑已变量化（var(--glow-1)）',
    strpos($css, 'var(--glow-1)') !== false && strpos($css, 'var(--glow-3)') !== false);

// 冲突检测：旧硬编码路径不应再出现在声明里（变量定义行除外）
$hardcodedUse = 0;
if (preg_match_all('/background:\s*url\(\'\.\.\/img\/(?:hero-sakura|pd-maid|auth-chibi|auth-cosmos|empty-state|bal-kotori|bal-guitar)\.webp\'\)/i', $css, $mm)) {
    $hardcodedUse = count($mm[0]);
}
check('无残留硬编码插画引用（全部改由变量驱动）', $hardcodedUse === 0,
    'n=' . $hardcodedUse);

// ============================================================
// 4. 蓝白素材文件
// ============================================================

group('4. 蓝白猫娘素材');

$blueDir = $ROOT . '/assets/img/bluecat';
$blueFiles = ['hero-cat.webp', 'pd-cat.webp', 'auth-chibi.webp',
              'empty-cat.webp', 'balance-cat.webp', 'steps-cat.webp'];
$missing = [];
$tooSmall = [];
foreach ($blueFiles as $f) {
    $p = $blueDir . '/' . $f;
    if (!is_file($p)) { $missing[] = $f; continue; }
    // 有效插画应明显大于占位图；8KB 下限足以排除空文件/坏图
    if (filesize($p) < 8 * 1024) { $tooSmall[] = $f; }
}
check('蓝白 6 张插画文件齐全', !$missing, implode(',', $missing));
check('蓝白插画体积合理（均 >8KB，非空文件）', !$tooSmall, implode(',', $tooSmall));

// WebP 魔数校验（RIFF....WEBP）
$webpOk = true;
foreach ($blueFiles as $f) {
    $p = $blueDir . '/' . $f;
    if (!is_file($p)) { $webpOk = false; continue; }
    $head = (string)file_get_contents($p, false, null, 0, 12);
    if (substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') { $webpOk = false; }
}
check('蓝白插画均为合法 WebP（RIFF/WEBP 魔数）', $webpOk);

// 回归：默认主题的旧插画仍在（切换回樱花粉不应缺图）
$sakuraImgs = ['hero-sakura.webp', 'pd-maid.webp', 'auth-chibi.webp',
               'auth-cosmos.webp', 'empty-state.webp', 'bal-kotori.webp', 'bal-guitar.webp'];
$sakuraMiss = [];
foreach ($sakuraImgs as $f) {
    if (!is_file($ROOT . '/assets/img/' . $f)) { $sakuraMiss[] = $f; }
}
check('默认主题旧插画保留（回切无缺图）', !$sakuraMiss, implode(',', $sakuraMiss));

// ============================================================
// 5. 前台渲染
// ============================================================

group('5. 前台渲染');

[$homeCode, $homeHtml] = httpGetTheme($BASE . '/index.php?r=home/index');
check('首页返回 200', $homeCode === 200, 'code=' . $homeCode);

check('<html> 无初始 data-theme（默认渲染樱花粉）',
    preg_match('/<html[^>]*>/', $homeHtml, $mHtml) === 1
    && strpos($mHtml[0], 'data-theme') === false,
    $mHtml[0] ?? '');

check('主题切换按钮已渲染（#themeToggle）', strpos($homeHtml, 'id="themeToggle"') !== false);
check('切换按钮含双图标节点（樱花 + 猫脸）',
    strpos($homeHtml, 'tt-ico-sakura') !== false && strpos($homeHtml, 'tt-ico-cat') !== false);
check('切换按钮含文案槽（data-theme-label）',
    strpos($homeHtml, 'data-theme-label') !== false);

// 防闪烁：预设脚本必须在 <head> 内、且早于 </head>
$headEndPos = strpos($homeHtml, '</head>');
$presetPos  = strpos($homeHtml, "localStorage.getItem('ly_theme')");
check('防闪烁预设脚本位于 <head> 内',
    $presetPos !== false && $headEndPos !== false && $presetPos < $headEndPos,
    'preset=' . var_export($presetPos, true) . ' headEnd=' . var_export($headEndPos, true));

$bodyPos = strpos($homeHtml, '<body');
check('预设脚本早于 <body>（首屏即为正确主题）',
    $presetPos !== false && $bodyPos !== false && $presetPos < $bodyPos);

check('预设脚本含蓝白主题分支（data-theme=bluecat）',
    strpos($homeHtml, "'bluecat'") !== false);

// 切换逻辑：键名与白名单
check('切换脚本使用 localStorage 键 ly_theme',
    substr_count($homeHtml, 'ly_theme') >= 2,
    'count=' . substr_count($homeHtml, 'ly_theme'));
check('localStorage 写入（记忆偏好）',
    strpos($homeHtml, "localStorage.setItem('ly_theme'") !== false);
check('主题值白名单（仅 sakura / bluecat）',
    strpos($homeHtml, "'sakura'") !== false && strpos($homeHtml, "'bluecat'") !== false);
check('点击事件绑定（切换交互）',
    substr_count($homeHtml, 'addEventListener') >= 1);

// 其他前台页面也应带切换入口
foreach ([['product/list', '全部商品'], ['auth/login', '登录页']] as [$r, $label]) {
    [$c, $h] = httpGetTheme($BASE . '/index.php?r=' . $r);
    check("{$label}渲染主题切换入口 + 200",
        $c === 200 && strpos($h, 'id="themeToggle"') !== false, 'code=' . $c);
}

// ============================================================
// 6. CSS 中的主题联动规则
// ============================================================

group('6. CSS 主题联动规则');

check('CSS 按 data-theme 控制图标显隐（猫脸默认隐藏）',
    strpos($css, 'html[data-theme="bluecat"] .theme-toggle .tt-ico-sakura { display: none; }') !== false
    || preg_match('/html\[data-theme="bluecat"\]\s+\.theme-toggle\s+\.tt-ico-sakura\s*\{\s*display:\s*none/', $css) === 1);
check('CSS 按 data-theme 显示猫脸图标',
    preg_match('/html\[data-theme="bluecat"\]\s+\.theme-toggle\s+\.tt-ico-cat\s*\{\s*display:\s*inline/', $css) === 1);
check('切换按钮基础样式存在（.theme-toggle）',
    preg_match('/\.theme-toggle\s*\{/', $css) === 1);
check('主题切换过渡动画已加（避免跳变）',
    strpos($css, 'transition: background-color .28s ease') !== false);

// ============================================================
// 7. 静态资源可访问性（防路由吞静态文件）
// ============================================================

group('7. 静态资源可访问性');

[$cssCode, $cssBody] = httpGetTheme($BASE . '/assets/css/app.css');
check('app.css 通过 HTTP 可访问（200）', $cssCode === 200, 'code=' . $cssCode);
check('线上 CSS 含蓝白主题块',
    strpos($cssBody, 'html[data-theme="bluecat"]') !== false);

foreach (['hero-cat.webp', 'auth-chibi.webp', 'empty-cat.webp'] as $f) {
    [$ic, ] = httpGetTheme($BASE . '/assets/img/bluecat/' . $f);
    check("蓝白插画 HTTP 可访问：{$f}", $ic === 200, 'code=' . $ic);
}
[$sc, ] = httpGetTheme($BASE . '/assets/img/hero-sakura.webp');
check('默认主题插画 HTTP 可访问（hero-sakura.webp）', $sc === 200, 'code=' . $sc);

// ============================================================
// 8. 后台隔离与回归
// ============================================================

group('8. 后台隔离与回归');

$adminCss = $ROOT . '/assets/css/admin.css';
$adminCssBody = is_file($adminCss) ? (string)file_get_contents($adminCss) : '';
check('后台样式不引用蓝白插画（访客偏好仅作用于前台）',
    strpos($adminCssBody, 'bluecat') === false);

// 后台头部不应含前台切换按钮
[$loginCode, $loginHtml] = httpGetTheme($BASE . '/index.php?r=admin/auth/login');
check('后台登录页正常渲染（200）且无前台主题按钮',
    $loginCode === 200 && strpos($loginHtml, 'id="themeToggle"') === false,
    'code=' . $loginCode);

// 回归：默认主题下关键样式规则仍在（未被主题重构破坏）
$regress = [
    '.hero::after'   => 'Hero 插画规则',
    '.pd-card::after' => '商品详情底纹规则',
    '.auth-page::before' => '登录页装饰规则',
    '.empty-state::before' => '空状态插画规则',
    '.bal-hero::after' => '余额页装饰规则',
    '.steps-section::before' => '流程区块底纹规则',
];
$broken = [];
foreach ($regress as $sel => $label) {
    $q = preg_quote($sel, '/');
    if (preg_match('/' . $q . '\s*\{/', $css) !== 1) { $broken[] = $label; }
}
check('默认主题既有插画规则完整（重构未破坏）', !$broken, implode(',', $broken));

// 页面无 PHP 报错残留
check('页面无 PHP 报错/警告字面残留',
    stripos($homeHtml, 'Fatal error') === false
    && stripos($homeHtml, 'Warning:') === false
    && stripos($homeHtml, 'Notice:') === false);

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "双主题测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
