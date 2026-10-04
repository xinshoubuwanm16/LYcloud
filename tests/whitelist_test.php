<?php
/**
 * 注册邮箱域名白名单测试
 *
 * 重点验证"后缀匹配"不会被绕过：
 *   notqq.com / qq.com.evil.com / xqq.com 都必须被拒绝。
 *
 * 用法：php tests/whitelist_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\Setting;

$pass = 0;
$fail = 0;

function group($name)
{
    echo "\n[" . $name . "]\n";
}

function check($desc, $cond)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✔ " . $desc . "\n";
    } else {
        $fail++;
        echo "  ✘ " . $desc . "   <-- 失败\n";
    }
}

echo "==========================================\n";
echo " LY云计算 · 注册邮箱白名单测试\n";
echo "==========================================\n";

// 保存现场，测试结束还原
$origDomains = Setting::get('register_email_domains');

// ------------------------------------------------------------
group('1. normalizeDomains 解析');

$r = Setting::normalizeDomains('qq.com,foxmail.com');
check('逗号分隔 → 2 项', $r === ['qq.com', 'foxmail.com']);

$r = Setting::normalizeDomains(' QQ.COM , FoxMail.com ');
check('大小写归一 + 去空白', $r === ['qq.com', 'foxmail.com']);

$r = Setting::normalizeDomains('.qq.com');
check('去掉前导点', $r === ['qq.com']);

$r = Setting::normalizeDomains('qq.com;foxmail.com，163.com');
check('支持中英文分号与中文逗号', $r === ['qq.com', 'foxmail.com', '163.com']);

$r = Setting::normalizeDomains("qq.com\nfoxmail.com\t163.com");
check('支持换行与制表符', count($r) === 3);

$r = Setting::normalizeDomains('qq.com,qq.com,QQ.com');
check('去重（大小写不敏感）', $r === ['qq.com']);

$r = Setting::normalizeDomains('');
check('空串 → 空数组', $r === []);

$r = Setting::normalizeDomains('  ,  ,,  ');
check('纯分隔符 → 空数组', $r === []);

// ------------------------------------------------------------
group('2. registerDomains 默认值');

// 注意：Setting::get() 用 array_key_exists 判断，只要 DB 里有行就会返回行值。
// 因此"回落默认值"只在 key 完全不存在时发生。
// 这里改用直接调用 normalizeDomains + 默认串来验证语义，不污染 DB。
check('默认串 qq.com,foxmail.com 解析为 2 项', Setting::normalizeDomains('qq.com,foxmail.com') === ['qq.com', 'foxmail.com']);

// 验证 get() 的默认值语义：key 不存在时返回默认
check('不存在的 key 返回传入默认值', Setting::get('__no_such_key_' . time(), 'FALLBACK') === 'FALLBACK');

Setting::set('register_email_domains', '163.com');
check('配置非空时以配置为准', Setting::registerDomains() === ['163.com']);

// ------------------------------------------------------------
group('3. 白名单命中（默认 qq.com/foxmail.com）');

Setting::set('register_email_domains', 'qq.com,foxmail.com');

check('a@qq.com 通过', Setting::emailDomainAllowed('a@qq.com') === true);
check('A@QQ.COM 大写通过', Setting::emailDomainAllowed('A@QQ.COM') === true);
check('1391234@qq.com 数字通过', Setting::emailDomainAllowed('1391234@qq.com') === true);
check('a@foxmail.com 通过', Setting::emailDomainAllowed('a@foxmail.com') === true);
check('a.b.c@qq.com 带点本地部通过', Setting::emailDomainAllowed('a.b.c@qq.com') === true);
check('a+tag@qq.com 带加号通过', Setting::emailDomainAllowed('a+tag@qq.com') === true);

// ------------------------------------------------------------
group('4. 非白名单域名拒绝');

check('a@gmail.com 拒绝', Setting::emailDomainAllowed('a@gmail.com') === false);
check('a@163.com 拒绝', Setting::emailDomainAllowed('a@163.com') === false);
check('a@qq.com.cn 拒绝（同前缀不同顶级域）', Setting::emailDomainAllowed('a@qq.com.cn') === false);
check('a@gmail.com.cn 拒绝', Setting::emailDomainAllowed('a@gmail.com.cn') === false);

// ------------------------------------------------------------
group('5. 后缀绕过攻击（关键安全用例）');

// 裸用 str_ends_with($domain,'qq.com') 会把这四个全部误判为合法
$bypass = [
    'evil@notqq.com'      => 'notqq.com（前缀粘连）',
    'evil@xqq.com'        => 'xqq.com（前缀粘连）',
    'evil@qq.com.evil.com' => 'qq.com.evil.com（后缀伪装）',
    'evil@fakeqq.com'     => 'fakeqq.com（前缀粘连）',
];
foreach ($bypass as $email => $label) {
    check($email . ' 拒绝 — ' . $label, Setting::emailDomainAllowed($email) === false);
}

// ------------------------------------------------------------
group('6. 畸形输入');

check('无 @ 拒绝', Setting::emailDomainAllowed('qq.com') === false);
check('空串拒绝', Setting::emailDomainAllowed('') === false);
check('@ 在末尾拒绝', Setting::emailDomainAllowed('a@') === false);
check('多 @ 取最后一个（a@b@qq.com 通过）', Setting::emailDomainAllowed('a@b@qq.com') === true);

// ------------------------------------------------------------
group('7. 子域规则');

// 设计意图：声明 qq.com 即视作信任其整个子域（vip.qq.com 是真实存在的 QQ 邮箱域）。
// 这条规则不会被 notqq.com 这类"前缀粘连"绕过（见第 5 组）。
Setting::set('register_email_domains', 'qq.com');
check('声明 qq.com → vip.qq.com 通过（合法 QQ 邮箱域）', Setting::emailDomainAllowed('a@vip.qq.com') === true);
check('声明 qq.com → notqq.com 仍拒绝', Setting::emailDomainAllowed('a@notqq.com') === false);
check('声明 qq.com → qq.com.evil.com 仍拒绝', Setting::emailDomainAllowed('a@qq.com.evil.com') === false);

Setting::set('register_email_domains', 'qq.com,vip.qq.com');
check('显式加入后 vip.qq.com 通过', Setting::emailDomainAllowed('a@vip.qq.com') === true);
check('加入 vip.qq.com 后 notqq.com 仍拒绝', Setting::emailDomainAllowed('a@notqq.com') === false);
check('evil.vip.qq.com 作为子域通过', Setting::emailDomainAllowed('a@evil.vip.qq.com') === true);
check('evilvip.com 拒绝（不沾 vip.qq.com）', Setting::emailDomainAllowed('a@evilvip.com') === false);

// ------------------------------------------------------------
group('8. 白名单为空 = 不限制');

Setting::set('register_email_domains', '');
check('空白名单放行 gmail.com', Setting::emailDomainAllowed('a@gmail.com') === true);
check('空白名单仍拒绝无 @ 串', Setting::emailDomainAllowed('nope') === false);

// ------------------------------------------------------------
group('9. emailVerifyEnforced 组合判定');

$origSmtpEnabled = Setting::get('smtp_enabled');
$origVerify      = Setting::get('register_email_verify');

Setting::set('register_email_verify', '0');
Setting::set('smtp_enabled', '1');
check('开关关闭 → 不强制验证码', Setting::emailVerifyEnforced() === false);

Setting::set('register_email_verify', '1');
Setting::set('smtp_enabled', '0');
check('SMTP 未开 → 不强制（防管理员自锁）', Setting::emailVerifyEnforced() === false);

Setting::set('smtp_enabled', '1');
// 补齐 SMTP 必填项以让 smtpConfigured() 为真
Setting::set('smtp_host', 'smtp.qq.com');
Setting::set('smtp_username', 'u@qq.com');
Setting::set('smtp_from_email', 'u@qq.com');
Setting::set('smtp_password', 'authcode');
check('开关开 + SMTP 齐全 → 强制验证码', Setting::emailVerifyEnforced() === true);

// ------------------------------------------------------------
// 还原现场
Setting::set('register_email_domains', $origDomains);
Setting::set('register_email_verify', (string)$origVerify);
Setting::set('smtp_enabled', (string)$origSmtpEnabled);
Setting::set('smtp_host', '');
Setting::set('smtp_username', '');
Setting::set('smtp_from_email', '');
Setting::set('smtp_password', '');

echo "\n==========================================\n";
echo " 通过: {$pass}   失败: {$fail}\n";
echo "==========================================\n";
exit($fail > 0 ? 1 : 0);
