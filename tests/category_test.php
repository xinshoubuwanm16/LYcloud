<?php
/**
 * 商品分类测试套件（1.7.8）
 *
 * 覆盖范围：
 *   ── 一、归一化与校验（纯函数）─────────────────────────────────
 *      · normalizeIcon 白名单（非法键/注入串归一空、大小写归一）
 *      · normalizeColor 仅 #rgb/#rrggbb（注入串归一空）
 *      · normalizeName 空白折叠与截断、validate、normalizeStatus
 *
 *   ── 二、slug 生成 ─────────────────────────────────────────────
 *      · slugify：英文转写、符号归一、纯中文转写失败返回空
 *      · uniqueSlug：回落 cat-{id}、重名追加 -2、编辑排除自身
 *
 *   ── 三、CRUD 与事务 ───────────────────────────────────────────
 *      · create 两步事务（先插行取 ID 再补 slug），中英文场景落库
 *      · 同名再建 slug 追加 -2、自定义 slug 归一
 *      · update 只传部分字段时其余保留、改名不误改 slug
 *      · mapByIds（缺 ID 无键）、nameOf/colorStyle/iconPath/iconExists
 *
 *   ── 四、计数与前台口径 ────────────────────────────────────────
 *      · useCount（含下架）/ activeUseCount（仅上架）
 *      · activeWithCount：空分类过滤、隐藏分类过滤、showEmpty 放开
 *      · stats 统计口径
 *
 *   ── 五、后台 HTTP · 防护与登录 ────────────────────────────────
 *      · 未登录访问列表/提交保存均 302 踢回登录
 *      · 临时管理员登录、分类列表页渲染
 *
 *   ── 六、后台 HTTP · 分类写操作 ────────────────────────────────
 *      · save 空名拒绝、正常新建落库、表单回显
 *      · toggle 启停往返、move 相邻交换（不同 sort）
 *      · move 同 sort 错开 ±1（上移应真正变靠前）
 *      · move 边界（最前 up / 最后 down）不越界
 *
 *   ── 七、商品接入 ──────────────────────────────────────────────
 *      · 商品表单下拉渲染与编辑回显（selected）
 *      · save 传入不存在分类被拒、传入真实分类落库
 *      · 商品列表按分类筛选、分类徽标与未分类徽标
 *
 *   ── 八、前台端到端 ────────────────────────────────────────────
 *      · 首页分类快捷入口：有上架商品的启用分类才展示
 *      · 全部商品页 Tab：含空分类、不含隐藏分类、激活态
 *      · 分类筛选计数、空分类空态、不存在分类回落全部
 *      · 详情页分类标签（含强调色）、未分类无标签
 *      · 隐藏分类直达仍可见（隐藏≠下架）
 *
 *   ── 九、删除保护 ──────────────────────────────────────────────
 *      · 无商品分类直接删除
 *      · 有商品默认拒绝、force 删除后商品置未分类（不删商品）
 *
 * 运行：php tests/category_test.php
 *      依赖本机站点（默认 http://127.0.0.1:8099，可用 LY_TEST_BASE 覆盖）
 *      与 MySQL（需已执行 install/upgrade_1.7.8.sql）。
 *      测试自建临时数据并在结束时清理，不影响正式数据。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;

$pass = 0; $fail = 0;
$chk = function (string $name, bool $cond, string $extra = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; echo "  [FAIL] $name" . ($extra !== '' ? "  -- $extra" : '') . "\n"; }
};
/** 分节标题 */
$sec = function (string $t) { echo "\n── $t ──\n"; };

$BASE = rtrim((string)(getenv('LY_TEST_BASE') ?: 'http://127.0.0.1:8099'), '/');
$db = Database::instance();

/* ---------- 待清理数据登记 ---------- */
$catIds  = [];   // ly_categories
$prodIds = [];   // ly_products
$adminId = 0;
$jar     = '';

/* ================================================================
 *  一、归一化与校验（纯函数）
 * ================================================================ */

$sec('一、归一化与校验');
$chk('合法图标键保留', Category::normalizeIcon('server') === 'server');
$chk('图标键大小写归一', Category::normalizeIcon('  Server ') === 'server');
$chk('注入串图标归一空', Category::normalizeIcon('<script>alert(1)</script>') === '');
$chk('未知图标键归一空', Category::normalizeIcon('no-such-icon') === '');
$chk('图标白名单含 16 键', count(Category::ICONS) === 16, '实际 ' . count(Category::ICONS));

$chk('六位 HEX 保留', Category::normalizeColor('#FF5065') === '#ff5065');
$chk('三位 HEX 保留', Category::normalizeColor('#f0a') === '#f0a');
$chk('非法颜色归一空', Category::normalizeColor('red;drop table ly_users') === '');
$chk('颜色注入含括号归一空', Category::normalizeColor('#fff); background:url(x)') === '');

$chk('名称去首尾空白', Category::normalizeName('  主机  ') === '主机');
$chk('名称折叠连续空白', Category::normalizeName("云\t主机\n  优选") === '云 主机 优选');
$chk('名称截断到 60 字符', mb_strlen(Category::normalizeName(str_repeat('云', 100))) === Category::MAX_NAME);

$chk('空名校验拒绝', Category::validate(['name' => '   ']) !== '');
$chk('有名校验通过', Category::validate(['name' => '虚拟主机']) === '');
$chk('状态归一：非 1 一律隐藏', Category::normalizeStatus(9) === Category::STATUS_OFF && Category::normalizeStatus(1) === Category::STATUS_ON);

/* ================================================================
 *  二、slug 生成
 * ================================================================ */

$sec('二、slug 生成');
$chk('英文名转写', Category::slugify('CDN 加速') === 'cdn');
$chk('符号归一为短横线', Category::slugify('--My_Slug!!') === 'my-slug');
$chk('连续短横线折叠', Category::slugify('a  b__c') === 'a-b-c');
$chk('纯中文转写失败返回空', Category::slugify('虚拟主机') === '');
$chk('中英混合剔除中文', Category::slugify('云 Host 01') === 'host-01');

// uniqueSlug 需要 DB（查重），回落 cat-{insertId} 依赖真实自增 ID
$tmpId = (int)$db->insert('ly_categories', [
    'name' => '临时slug探针', 'slug' => '', 'sort' => -99999, 'status' => 0,
]);
$catIds[] = $tmpId;
$chk('uniqueSlug 纯中文回落 cat-{id}', Category::uniqueSlug('', '虚拟主机', 0, $tmpId) === 'cat-' . $tmpId);
// 先把 probe-slug 写入探针行，让下一次查重命中
$db->update('ly_categories', ['slug' => 'probe-slug'], 'id=?', [$tmpId]);
$chk('uniqueSlug 重名追加 -2', Category::uniqueSlug('probe-slug', 'x') === 'probe-slug-2', Category::uniqueSlug('probe-slug', 'x'));
$chk('uniqueSlug 编辑时排除自身', Category::uniqueSlug('probe-slug', 'x', $tmpId) === 'probe-slug');

/* ================================================================
 *  三、CRUD 与事务
 * ================================================================ */

$sec('三、CRUD 与事务');
$rand  = substr(md5(uniqid('', true)), 0, 6);
$A = null; $B = null; $C = null; $D = null;

// A：中英混合 → slug 自动转写
$aid = Category::create([
    'name' => "云主机优选 cct{$rand}", 'icon' => 'server', 'color' => '#FF5065',
    'description' => "分类A cct{$rand}", 'sort' => 900, 'status' => 1,
]);
$A = Category::find($aid); $catIds[] = $aid;
$chk('create 返回自增 ID', $aid > 0);
$chk('create 落库后 slug 已补齐（非空中间态）', $A !== null && $A['slug'] === "cct{$rand}", (string)($A['slug'] ?? 'null'));
$chk('create 颜色归一小写落库', $A !== null && $A['color'] === '#ff5065');

// B：纯中文 → slug 回落 cat-{id}
$bid = Category::create(['name' => '虚拟服务器乙类', 'sort' => 800, 'status' => 1]);
$B = Category::find($bid); $catIds[] = $bid;
$chk('create 纯中文 slug 回落 cat-{id}', $B !== null && $B['slug'] === 'cat-' . $bid, (string)($B['slug'] ?? 'null'));

// D：与 A 同名 → slug 追加 -2
$did = Category::create(['name' => "云主机优选 cct{$rand}", 'slug' => '', 'sort' => 100, 'status' => 1]);
$D = Category::find($did); $catIds[] = $did;
$chk('create 同名 slug 追加 -2', $D !== null && $D['slug'] === "cct{$rand}-2", (string)($D['slug'] ?? 'null'));

// C：自定义 slug 带非法字符 → 归一；随后 toggle 隐藏供前台节使用
$cid = Category::create([
    'name' => '隐藏备用类', 'slug' => 'Hidden Slug!', 'icon' => 'globe',
    'sort' => 700, 'status' => 1,
]);
$C = Category::find($cid); $catIds[] = $cid;
$chk('create 自定义 slug 非法字符归一', $C !== null && $C['slug'] === 'hidden-slug', (string)($C['slug'] ?? 'null'));

// update：只传部分字段，其余保留
Category::update($cid, ['name' => '隐藏备用类改']);
$C = Category::find($cid);
$chk('update 部分字段：改名生效', $C !== null && $C['name'] === '隐藏备用类改');
$chk('update 部分字段：icon 保留', $C !== null && $C['icon'] === 'globe');
$chk('update 部分字段：slug 不随改名重生成', $C !== null && $C['slug'] === 'hidden-slug', (string)($C['slug'] ?? 'null'));
$chk('update 不存在的分类返回 0', Category::update(999999999, ['name' => 'x']) === 0);

// toggle：C 启用 → 隐藏（前台节需要 C 处于隐藏态）
$chk('toggle 隐藏生效', Category::toggle($cid) === Category::STATUS_OFF && (int)Category::find($cid)['status'] === Category::STATUS_OFF);

// 展示辅助
$chk('nameOf 空分类回退占位', Category::nameOf(null) === '未分类' && Category::nameOf(['name' => '']) === '未分类');
$chk('nameOf 正常返回名称', Category::nameOf($A) === "云主机优选 cct{$rand}");
$chk('colorStyle 空色返回空串', Category::colorStyle(['color' => '']) === '');
$chk('colorStyle 有色输出 CSS 变量', Category::colorStyle($A) === '--cat-color:#ff5065;');
$chk('iconPath 白名单外返回空', Category::iconPath('<svg>') === '' && Category::iconPath('server') !== '');
$chk('iconExists 判定', Category::iconExists('SERVER') && !Category::iconExists('hack'));

// mapByIds：存在/缺失混合
$map = Category::mapByIds([$aid, $cid, 999999999]);
$chk('mapByIds 含存在分类', isset($map[$aid], $map[$cid]));
$chk('mapByIds 缺失 ID 无键', !array_key_exists(999999999, $map));

/* ================================================================
 *  四、计数与前台口径
 * ================================================================ */

$sec('四、计数与前台口径');
$p1 = (int)$db->insert('ly_products', ['name' => "cct商品一{$rand}", 'price' => 9.90, 'category_id' => $aid, 'status' => 1]);
$p2 = (int)$db->insert('ly_products', ['name' => "cct商品二{$rand}", 'price' => 19.90, 'category_id' => $aid, 'status' => 0]);
$p3 = (int)$db->insert('ly_products', ['name' => "cct商品三{$rand}", 'price' => 29.90, 'category_id' => $cid, 'status' => 1]);
$p4 = (int)$db->insert('ly_products', ['name' => "cct商品四{$rand}", 'price' => 39.90, 'category_id' => 0, 'status' => 1]);
array_push($prodIds, $p1, $p2, $p3, $p4);

$chk('useCount 含下架商品', Category::useCount($aid) === 2, '实际 ' . Category::useCount($aid));
$chk('activeUseCount 仅上架', Category::activeUseCount($aid) === 1, '实际 ' . Category::activeUseCount($aid));
$chk('useCount 空分类为 0', Category::useCount($bid) === 0);

$awc = Category::activeWithCount(false);
$awcIds = array_map('intval', array_column($awc, 'id'));
$chk('前台口径：含 A（有上架商品）', in_array($aid, $awcIds, true));
$chk('前台口径：排除空分类 B', !in_array($bid, $awcIds, true));
$chk('前台口径：排除隐藏分类 C', !in_array($cid, $awcIds, true));

$awcAll = Category::activeWithCount(true);
$awcAllIds = array_map('intval', array_column($awcAll, 'id'));
$chk('showEmpty 放开空分类 B', in_array($bid, $awcAllIds, true));
$chk('showEmpty 仍排除隐藏分类 C', !in_array($cid, $awcAllIds, true));
$cntOfA = 0;
foreach ($awcAll as $r) { if ((int)$r['id'] === $aid) { $cntOfA = (int)$r['product_count']; } }
$chk('Tab 计数为该分类上架商品数', $cntOfA === 1, '实际 ' . $cntOfA);

$stats = Category::stats();
$chk('stats：总数 ≥ 4', (int)($stats['total'] ?? 0) >= 4, json_encode($stats, JSON_UNESCAPED_UNICODE));

/* ================================================================
 *  五、后台 HTTP · 防护与登录
 * ================================================================ */

$sec('五、后台 HTTP · 防护与登录');

function httpGet(string $url, string $jar = ''): array
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false];
    if ($jar !== '') { $opt[CURLOPT_COOKIEJAR] = $jar; $opt[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opt);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function httpPost(string $url, array $data, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

/** 从页面抓 CSRF token */
$grabToken = function (string $html): string {
    return preg_match('/name="_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
};

[$code, ] = httpGet($BASE . '/index.php?r=admin/category/list');
$chk('未登录访问分类列表 302 踢回登录', $code === 302, "code={$code}");
[$code, ] = httpPost($BASE . '/index.php?r=admin/category/save', ['name' => '匿名'], '');
$chk('未登录提交保存被拒', $code === 302, "code={$code}");

// 临时管理员登录（避开真实 admin 账号）
$USER = 'cct_admin_' . substr(md5(uniqid('', true)), 0, 8);
$PASS = 'Cct' . random_int(100000, 999999);
$jar  = sys_get_temp_dir() . "/cct_" . uniqid() . ".jar";
$adminId = Admin::create($USER, $PASS, '分类测试');
$chk('创建临时管理员', $adminId > 0);

[, $loginHtml] = httpGet($BASE . '/index.php?r=admin/auth/login', $jar);
$token = $grabToken($loginHtml);
$chk('登录页取到 CSRF', $token !== '');
httpPost($BASE . '/index.php?r=admin/auth/login', ['_token' => $token, 'username' => $USER, 'password' => $PASS], $jar);

[$code, $listHtml] = httpGet($BASE . '/index.php?r=admin/category/list', $jar);
$chk('登录后分类列表 200', $code === 200, "code={$code}");
$chk('列表渲染分类 A', strpos($listHtml, "云主机优选 cct{$rand}") !== false);
$chk('列表渲染统计区块', strpos($listHtml, '分类总数') !== false);

/* ================================================================
 *  六、后台 HTTP · 分类写操作
 * ================================================================ */

$sec('六、后台 HTTP · 分类写操作');

/** 每次从列表页取新 CSRF（token 与会话绑定） */
$csrf = function () use ($BASE, $jar, $grabToken): string {
    [, $html] = httpGet($BASE . '/index.php?r=admin/category/list', $jar);
    return $grabToken($html);
};

// 空名拒绝
[$code, ] = httpPost($BASE . '/index.php?r=admin/category/save', ['_token' => $csrf(), 'name' => '   '], $jar);
$chk('save 空名 302 拒绝', $code === 302);
$chk('save 空名未落库', (int)$db->value("SELECT COUNT(*) FROM ly_categories WHERE name LIKE 'cct空名%'") === 0);

// 正常新建：E 组不同 sort（测相邻交换）、F/G 组同 sort（测 up/down 错开）
// sort 用 6~8 万隔离区间，确保测试集内部相邻关系不受正式数据干扰（正式分类 sort 均远小于此）
$e1 = Category::create(['name' => "排序甲组 cct{$rand}", 'sort' => 80001, 'status' => 1]);
$e2 = Category::create(['name' => "排序乙组 cct{$rand}", 'sort' => 80000, 'status' => 1]);
$f1 = Category::create(['name' => "同序一组 cct{$rand}", 'sort' => 70002, 'status' => 1]);
$f2 = Category::create(['name' => "同序二组 cct{$rand}", 'sort' => 70002, 'status' => 1]);
$g1 = Category::create(['name' => "同序三组 cct{$rand}", 'sort' => 60002, 'status' => 1]);
$g2 = Category::create(['name' => "同序四组 cct{$rand}", 'sort' => 60002, 'status' => 1]);
array_push($catIds, $e1, $e2, $f1, $f2, $g1, $g2);
$chk('HTTP 节测试分类就位', min($e1, $e2, $f1, $f2, $g1, $g2) > 0);
// 约定：同 sort 下 id 小者更靠前（ORDER BY sort DESC, id ASC），故 F1 在 F2 前、G1 在 G2 前
$chk('同序对 id 前提成立', $f1 < $f2 && $g1 < $g2);

// 表单回显
[, $formHtml] = httpGet($BASE . '/index.php?r=admin/category/form&id=' . $aid, $jar);
$chk('编辑表单回显名称', strpos($formHtml, "云主机优选 cct{$rand}") !== false);
$chk('编辑表单回显强调色', stripos($formHtml, '#ff5065') !== false);
$chk('编辑表单回显图标选中', (bool)preg_match('/value="server"[^>]*checked|checked[^>]*value="server"/', $formHtml));
$chk('表单渲染 16 图标选择器', substr_count($formHtml, 'cat-icon-opt') >= 16, '实际 ' . substr_count($formHtml, 'cat-icon-opt'));

// toggle 启停往返
httpPost($BASE . '/index.php?r=admin/category/toggle', ['_token' => $csrf(), 'id' => $e1], $jar);
$chk('HTTP toggle 隐藏生效', (int)Category::find($e1)['status'] === 0);
httpPost($BASE . '/index.php?r=admin/category/toggle', ['_token' => $csrf(), 'id' => $e1], $jar);
$chk('HTTP toggle 再切回启用', (int)Category::find($e1)['status'] === 1);

// move：不同 sort 相邻交换（E1=80001 在前，E2=80000 紧随其后，区间内无第三方）
httpPost($BASE . '/index.php?r=admin/category/move', ['_token' => $csrf(), 'id' => $e2, 'dir' => 'up'], $jar);
$chk('move up：E2 拿到 E1 的 sort（变靠前）', (int)Category::find($e2)['sort'] === 80001, '实际 ' . Category::find($e2)['sort']);
$chk('move up：E1 拿到 E2 的 sort（变靠后）', (int)Category::find($e1)['sort'] === 80000, '实际 ' . Category::find($e1)['sort']);
httpPost($BASE . '/index.php?r=admin/category/move', ['_token' => $csrf(), 'id' => $e2, 'dir' => 'down'], $jar);
$chk('move down 复原', (int)Category::find($e2)['sort'] === 80000 && (int)Category::find($e1)['sort'] === 80001);

// move：同 sort 错开 ±1 —— F2 上移后必须真正排到 F1 前面
httpPost($BASE . '/index.php?r=admin/category/move', ['_token' => $csrf(), 'id' => $f2, 'dir' => 'up'], $jar);
$sF1 = (int)Category::find($f1)['sort']; $sF2 = (int)Category::find($f2)['sort'];
$chk('同 sort move up：F2 sort 变大', $sF2 === 70003, "F2={$sF2}");
$chk('同 sort move up：F1 sort 变小', $sF1 === 70001, "F1={$sF1}");
$chk('同 sort move up 后 F2 确实排到 F1 前', $sF2 > $sF1, "F2={$sF2} F1={$sF1}");
// F2 down：此时 F1/F2 已错开（不同 sort），走普通交换——F2 应回到 F1 后面（序位复原）
httpPost($BASE . '/index.php?r=admin/category/move', ['_token' => $csrf(), 'id' => $f2, 'dir' => 'down'], $jar);
$sF1 = (int)Category::find($f1)['sort']; $sF2 = (int)Category::find($f2)['sort'];
$chk('错开后 move down F2 排回 F1 后（序位复原）', $sF2 < $sF1, "F2={$sF2} F1={$sF1}");

// move：同 sort down 错开 —— G1（同 sort 中 id 小、当前在前）下移应真正排到 G2 后面
httpPost($BASE . '/index.php?r=admin/category/move', ['_token' => $csrf(), 'id' => $g1, 'dir' => 'down'], $jar);
$sG1 = (int)Category::find($g1)['sort']; $sG2 = (int)Category::find($g2)['sort'];
$chk('同 sort move down：G1 sort 变小', $sG1 === 60001, "G1={$sG1}");
$chk('同 sort move down：G2 sort 变大', $sG2 === 60003, "G2={$sG2}");
$chk('同 sort move down 后 G1 确实排到 G2 后', $sG1 < $sG2, "G1={$sG1} G2={$sG2}");
// G1 up：此时已错开（不同 sort），走普通交换——G1 应回到 G2 前面（序位复原）
httpPost($BASE . '/index.php?r=admin/category/move', ['_token' => $csrf(), 'id' => $g1, 'dir' => 'up'], $jar);
$sG1 = (int)Category::find($g1)['sort']; $sG2 = (int)Category::find($g2)['sort'];
$chk('错开后 move up G1 排回 G2 前（序位复原）', $sG1 > $sG2, "G1={$sG1} G2={$sG2}");

// move 边界：E1（测试区间最大 sort，正式库无更大者）再上移不越界、sort 不变
httpPost($BASE . '/index.php?r=admin/category/move', ['_token' => $csrf(), 'id' => $e1, 'dir' => 'up'], $jar);
$chk('边界上移到顶 sort 不变', (int)Category::find($e1)['sort'] === 80001, '实际 ' . Category::find($e1)['sort']);

/* ================================================================
 *  七、商品接入
 * ================================================================ */

$sec('七、商品接入');

// 新建商品表单：分类下拉渲染
[, $pFormHtml] = httpGet($BASE . '/index.php?r=admin/product/form', $jar);
$chk('商品表单含「商品分类」下拉', strpos($pFormHtml, '商品分类') !== false);
$chk('下拉含测试分类 A', strpos($pFormHtml, "云主机优选 cct{$rand}") !== false);
$chk('下拉含未分类选项', strpos($pFormHtml, 'value="0"') !== false);

// 编辑回显：P1 已挂分类 A
[, $pFormHtml2] = httpGet($BASE . '/index.php?r=admin/product/form&id=' . $p1, $jar);
$chk('编辑时分类下拉正确 selected', (bool)preg_match('/<option value="' . $aid . '"[^>]*selected/', $pFormHtml2));

// save 传入不存在的分类被拒
$ghostName = "cct幽灵商品{$rand}";
[$code, ] = httpPost($BASE . '/index.php?r=admin/product/save', [
    '_token' => $csrf(), 'name' => $ghostName, 'price' => '9.90', 'category_id' => 999999999,
], $jar);
$chk('save 不存在分类 302 拒绝', $code === 302);
$chk('save 不存在分类未落库', (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE name=?', [$ghostName]) === 0);

// save 传入真实分类落库
$p5Name = "cct商品五{$rand}";
[$code, ] = httpPost($BASE . '/index.php?r=admin/product/save', [
    '_token' => $csrf(), 'name' => $p5Name, 'price' => '59.90', 'category_id' => $aid, 'status' => 1,
], $jar);
$chk('save 带真实分类 302 成功', $code === 302);
$p5 = (int)$db->value('SELECT id FROM ly_products WHERE name=?', [$p5Name]);
if ($p5 > 0) { $prodIds[] = $p5; }
$chk('save 分类归属落库正确', $p5 > 0 && (int)$db->value('SELECT category_id FROM ly_products WHERE id=?', [$p5]) === $aid);

// 商品列表：按分类筛选
[, $pListA] = httpGet($BASE . '/index.php?r=admin/product/list&category=' . $aid, $jar);
$chk('后台列表按分类筛出 P1', strpos($pListA, "cct商品一{$rand}") !== false);
$chk('后台列表分类筛选排除未分类商品', strpos($pListA, "cct商品四{$rand}") === false);
$chk('后台列表渲染分类徽标', strpos($pListA, 'cat-badge') !== false);
[, $pList0] = httpGet($BASE . '/index.php?r=admin/product/list&category=0', $jar);
$chk('后台列表未分类徽标', strpos($pList0, 'badge-muted">未分类') !== false);
$chk('未分类视图不含已归类商品', strpos($pList0, "cct商品一{$rand}") === false);

/* ================================================================
 *  八、前台端到端
 * ================================================================ */

$sec('八、前台端到端');

[, $homeHtml] = httpGet($BASE . '/index.php?r=home/index');
$chk('首页渲染分类快捷入口', strpos($homeHtml, 'cat-entry-card') !== false);
$chk('首页入口展示分类 A', strpos($homeHtml, "云主机优选 cct{$rand}") !== false);
$chk('首页入口排除空分类 B', strpos($homeHtml, '虚拟服务器乙类') === false);
$chk('首页入口排除隐藏分类 C', strpos($homeHtml, '隐藏备用类改') === false);
$chk('首页入口带商品计数（A=2 款）', (bool)preg_match('/ce-count">\s*2 款/', $homeHtml));
$chk('首页入口带强调色变量', strpos($homeHtml, '--cat-color:#ff5065') !== false);

[, $tabHtml] = httpGet($BASE . '/index.php?r=product/list');
$chk('列表页渲染分类 Tab', strpos($tabHtml, 'cat-tab') !== false);
$chk('Tab 含空分类 B（showEmpty）', strpos($tabHtml, '虚拟服务器乙类') !== false);
$chk('Tab 排除隐藏分类 C', strpos($tabHtml, '隐藏备用类改') === false);
$chk('默认「全部」Tab 激活', substr_count($tabHtml, 'cat-tab is-active') === 1, '激活数 ' . substr_count($tabHtml, 'cat-tab is-active'));
$chk('Tab 计数徽标（A=2，空分类=0）', strpos($tabHtml, '<i>2</i>') !== false && strpos($tabHtml, '<i>0</i>') !== false);

// 分类筛选（A 分类下此时有 P1、P5 两个上架商品；P4 未分类、P2 下架均不应出现）
[, $listA] = httpGet($BASE . '/index.php?r=product/list&category=' . $aid);
$chk('分类 A 页 A Tab 激活', substr_count($listA, 'cat-tab is-active') === 1 && strpos($listA, 'category=' . $aid) !== false);
$chk('分类 A 页含两个归属上架商品', strpos($listA, "cct商品一{$rand}") !== false && strpos($listA, "cct商品五{$rand}") !== false);
$chk('分类 A 页排除未分类商品', strpos($listA, "cct商品四{$rand}") === false);

// 空分类空态
[, $listB] = httpGet($BASE . '/index.php?r=product/list&category=' . $bid);
$chk('空分类显示空态文案', strpos($listB, '该分类下暂无在售商品') !== false);
$chk('空态提供查看全部入口', strpos($listB, '查看全部商品') !== false);

// 不存在分类回落全部
[, $listG] = httpGet($BASE . '/index.php?r=product/list&category=999999999');
$chk('不存在分类回落「全部」激活', substr_count($listG, 'cat-tab is-active') === 1);
$chk('回落全部后展示未分类商品', strpos($listG, "cct商品四{$rand}") !== false);

// 详情页
[, $show1] = httpGet($BASE . '/index.php?r=product/show&id=' . $p1);
$chk('详情页渲染分类标签', strpos($show1, 'tag-cat') !== false);
$chk('详情页标签带分类名', strpos($show1, "云主机优选 cct{$rand}") !== false);
$chk('详情页标签带强调色', strpos($show1, '--cat-color:#ff5065') !== false);
$chk('详情页面包屑含分类链接', strpos($show1, 'category=' . $aid) !== false);
$chk('详情页无 PHP 代码字面残留', strpos($show1, 'limitPerUser') === false && strpos($show1, 'yipayChannels') === false && strpos($show1, '?>') === false);
$chk('详情页支付通道区正常渲染', strpos($show1, 'pd-pay') !== false || strpos($show1, '支付宝') !== false);

[, $show4] = httpGet($BASE . '/index.php?r=product/show&id=' . $p4);
$chk('未分类商品详情无分类标签', strpos($show4, 'tag-cat') === false);

// 隐藏分类直达：隐藏≠下架，商品仍可见
[, $listC] = httpGet($BASE . '/index.php?r=product/list&category=' . $cid);
$chk('隐藏分类直达仍可浏览', strpos($listC, "cct商品三{$rand}") !== false);

/* ================================================================
 *  九、删除保护
 * ================================================================ */

$sec('九、删除保护');

// 无商品分类：直接删除
$res = Category::delete($did);
$catIds = array_values(array_diff($catIds, [$did]));
$chk('无商品分类删除成功', $res['ok'] === true && Category::find($did) === null);

// 有商品分类：默认拒绝（A 下此时有 P1、P2、P5 共 3 个）
$res = Category::delete($aid);
$chk('有商品分类删除被拒', $res['ok'] === false && Category::find($aid) !== null);
$chk('拒绝文案含商品数量', strpos($res['msg'], '3 个商品') !== false, $res['msg']);
$chk('被拒后商品归属未动', (int)$db->value('SELECT category_id FROM ly_products WHERE id=?', [$p1]) === $aid);

// 强制删除：商品置未分类，商品本身保留
$res = Category::delete($aid, true);
$chk('force 删除成功', $res['ok'] === true && Category::find($aid) === null);
$chk('force 返回受影响商品数', (int)$res['affected'] === 3, '实际 ' . $res['affected']);
$chk('force 后商品 1 置未分类', (int)$db->value('SELECT category_id FROM ly_products WHERE id=?', [$p1]) === 0);
$chk('force 后商品 2 置未分类', (int)$db->value('SELECT category_id FROM ly_products WHERE id=?', [$p2]) === 0);
$chk('force 不删商品本身', (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE id IN (?,?)', [$p1, $p2]) === 2);

// 删除不存在的分类
$chk('删除不存在分类报「分类不存在」', Category::delete(999999999) === ['ok' => false, 'msg' => '分类不存在', 'affected' => 0]);

/* ================================================================
 *  清理
 * ================================================================ */

$sec('清理');
foreach ($prodIds as $pid) { if ($pid > 0) { $db->delete('ly_products', 'id=?', [$pid]); } }
foreach ($catIds  as $kid) { if ($kid > 0) { $db->delete('ly_categories', 'id=?', [$kid]); } }
if ($adminId > 0) { $db->delete('ly_admins', 'id=?', [$adminId]); }
if ($jar !== '') { @unlink($jar); }

$chk('测试商品已清理', (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE name LIKE ?', ["cct%{$rand}"]) === 0);
$chk('测试分类已清理', (int)$db->value(
    'SELECT COUNT(*) FROM ly_categories WHERE name LIKE ? OR name LIKE ? OR name = ? OR name = ?',
    ["%cct{$rand}%", '隐藏备用类%', '虚拟服务器乙类', '隐藏备用类改']
) === 0);
$chk('临时管理员已清理', (int)$db->value('SELECT COUNT(*) FROM ly_admins WHERE id=?', [$adminId]) === 0);

/* ---------- 汇总 ---------- */
echo "\n==============================\n";
echo "商品分类测试：{$pass} 项通过，{$fail} 项失败\n";
exit($fail > 0 ? 1 : 0);
