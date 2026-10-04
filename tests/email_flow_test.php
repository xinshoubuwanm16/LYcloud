<?php
/**
 * 邮箱验证码 / 找回密码令牌 流程测试
 *
 * 覆盖：issue 生成与入库（只存 hash）、冷却、verify 成功/失败/尝试上限/过期、
 *       consume 防重放；PasswordReset 的 issue/findValid/consume/批量作废/过期。
 *
 * 用法：php tests/email_flow_test.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Models\EmailCode;
use App\Models\PasswordReset;
use App\Models\User;

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

/** 造一个本测试专用、肯定不会与真实用户冲突的邮箱 */
function tmail(string $tag): string
{
    return 'testflow_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 8) . '@qq.com';
}

$db = Database::instance();

echo "==========================================\n";
echo " LY云计算 · 验证码与找回密码流程测试\n";
echo "==========================================\n";

$createdUsers = [];
$usedEmails   = [];

// ------------------------------------------------------------
group('1. issue() 生成验证码');

$e1  = tmail('a');
$usedEmails[] = $e1;
$r1  = EmailCode::issue($e1, EmailCode::PURPOSE_REGISTER, '127.0.0.1');

check('返回 ok=true', $r1['ok'] === true);
check('验证码为 6 位', preg_match('/^\d{6}$/', $r1['code']) === 1);
check('返回 retry_after 为冷却秒数', (int)$r1['retry_after'] === EmailCode::COOLDOWN);

$row = $db->first('SELECT * FROM ly_email_codes WHERE `email`=? AND `purpose`=?', [$e1, 'register']);
check('库中存在记录', !empty($row));
check('库中不存明文验证码', !empty($row) && (string)$row['code_hash'] !== $r1['code']);
check('库中存 sha256(明文)', !empty($row) && (string)$row['code_hash'] === hash('sha256', $r1['code']));
check('初始 attempts=0', (int)$row['attempts'] === 0);
check('expires_at 约为 10 分钟后', !empty($row) && abs(strtotime((string)$row['expires_at']) - (time() + EmailCode::TTL)) <= 5);
check('记录了来源 IP', (string)$row['ip'] === '127.0.0.1');
check('lastSentAt 有值', EmailCode::lastSentAt($e1) > 0);

// 邮箱大小写归一
$e1upper = strtoupper($e1);
check('大写邮箱查到同一条记录', EmailCode::lastSentAt($e1upper) === EmailCode::lastSentAt($e1));

// ------------------------------------------------------------
group('2. issue() 冷却');

$r2 = EmailCode::issue($e1, EmailCode::PURPOSE_REGISTER);
check('冷却期内二次发送 ok=false', $r2['ok'] === false);
check('冷却期内返回 retry_after>0', (int)$r2['retry_after'] > 0);
check('冷却期内 retry_after<=冷却秒数', (int)$r2['retry_after'] <= EmailCode::COOLDOWN);

// cooldown 显式传 0 ⇒ 应当允许立刻重发，且生成新码
$r3 = EmailCode::issue($e1, EmailCode::PURPOSE_REGISTER, '', EmailCode::TTL, 0);
check('cooldown=0 时可立即重发', $r3['ok'] === true);
check('重发后码已变化', $r3['code'] !== $r1['code']);
$rowAfter = $db->first('SELECT * FROM ly_email_codes WHERE `email`=? AND `purpose`=?', [$e1, 'register']);
check('重发为覆盖式更新（仍只有 1 条）', (int)$db->first('SELECT COUNT(*) AS c FROM ly_email_codes WHERE `email`=?', [$e1])['c'] === 1);
check('覆盖后 hash 对应新码', (string)$rowAfter['code_hash'] === hash('sha256', $r3['code']));
check('覆盖后 attempts 归零', (int)$rowAfter['attempts'] === 0);

// ------------------------------------------------------------
group('3. verify() 成功路径');

$e2 = tmail('b');
$usedEmails[] = $e2;
$r  = EmailCode::issue($e2);
$v  = EmailCode::verify($e2, $r['code']);
check('正确验证码 → ok=true', $v['ok'] === true);
check('成功后被销毁（防重放）', $db->first('SELECT * FROM ly_email_codes WHERE `email`=?', [$e2]) === null);
$v2 = EmailCode::verify($e2, $r['code']);
check('同码二次校验失败', $v2['ok'] === false);

// ------------------------------------------------------------
group('4. verify() 失败路径');

$e3 = tmail('c');
$usedEmails[] = $e3;
$r  = EmailCode::issue($e3);

check('空验证码被拒', EmailCode::verify($e3, '')['ok'] === false);
check('空邮箱被拒', EmailCode::verify('', $r['code'])['ok'] === false);
check('不存在的邮箱被拒', EmailCode::verify(tmail('zz'), $r['code'])['ok'] === false);
check('错误验证码被拒', EmailCode::verify($e3, '000000')['ok'] === false);

$row = $db->first('SELECT `attempts` FROM ly_email_codes WHERE `email`=?', [$e3]);
check('失败后 attempts 累加为 1', (int)$row['attempts'] === 1);

// 剩余次数提示
$v = EmailCode::verify($e3, '000000');
check('提示剩余尝试次数', strpos($v['msg'], '还可尝试') !== false);

// 打满 5 次
// 此时已失败 2 次（上述两条），再失败 2 次会到 attempts=4，第 5 次触发锁定
EmailCode::verify($e3, '000000');  // attempts=3
EmailCode::verify($e3, '000000');  // attempts=4
$vLast = EmailCode::verify($e3, '000000'); // attempts=5 → 锁定
check('第 5 次失败返回"次数过多"', strpos($vLast['msg'], '次数过多') !== false);
check('超限后记录被删除', $db->first('SELECT * FROM ly_email_codes WHERE `email`=?', [$e3]) === null);
$vGone = EmailCode::verify($e3, '000000');
check('锁定后再校验提示记录已失效', strpos($vGone['msg'], '不存在或已失效') !== false);

// ------------------------------------------------------------
group('5. verify() 过期');

$e4 = tmail('d');
$usedEmails[] = $e4;
$r  = EmailCode::issue($e4);
// 手动把过期时间改到过去
$db->query('UPDATE ly_email_codes SET `expires_at`=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `email`=?', [$e4]);
$v = EmailCode::verify($e4, $r['code']);
check('过期验证码被拒', $v['ok'] === false);
check('过期提示语正确', strpos($v['msg'], '过期') !== false);
check('过期后记录被清理', $db->first('SELECT * FROM ly_email_codes WHERE `email`=?', [$e4]) === null);

// ------------------------------------------------------------
group('6. purpose 隔离');

$e5 = tmail('e');
$usedEmails[] = $e5;
$rr = EmailCode::issue($e5, EmailCode::PURPOSE_RESET);
check('reset 用途发码 ok=true', $rr['ok'] === true);

$vr = EmailCode::verify($e5, $rr['code'], EmailCode::PURPOSE_REGISTER);
check('register 用途校验 reset 的码 → 失败', $vr['ok'] === false);

$vr2 = EmailCode::verify($e5, $rr['code'], EmailCode::PURPOSE_RESET);
check('reset 用途校验自己的码 → 成功', $vr2['ok'] === true);
check('reset 码验证成功后已销毁', EmailCode::lastSentAt($e5, EmailCode::PURPOSE_RESET) === 0);

// 两用途彻底隔离：给 register 发码不影响 reset 的冷却状态
EmailCode::issue($e5, EmailCode::PURPOSE_REGISTER);
check('register 发码后 register 冷却生效可读', EmailCode::lastSentAt($e5, EmailCode::PURPOSE_REGISTER) > 0);
$rr2 = EmailCode::issue($e5, EmailCode::PURPOSE_RESET);
check('register 冷却不阻塞 reset 发码', $rr2['ok'] === true);

// 两个用途并存
$e6 = tmail('f');
$usedEmails[] = $e6;
EmailCode::issue($e6, EmailCode::PURPOSE_REGISTER);
EmailCode::issue($e6, EmailCode::PURPOSE_RESET);
$cnt = $db->first('SELECT COUNT(*) AS c FROM ly_email_codes WHERE `email`=?', [$e6]);
check('同邮箱两用途各一条记录', (int)$cnt['c'] === 2);

// ------------------------------------------------------------
group('7. consume() 与 purgeExpired()');

$e7 = tmail('g');
$usedEmails[] = $e7;
$r = EmailCode::issue($e7);
EmailCode::consume($e7);
check('consume 后记录消失', $db->first('SELECT * FROM ly_email_codes WHERE `email`=?', [$e7]) === null);
EmailCode::consume($e7);
check('重复 consume 不报错', true);

$e8 = tmail('h');
$usedEmails[] = $e8;
EmailCode::issue($e8);
$db->query('UPDATE ly_email_codes SET `expires_at`=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE `email`=?', [$e8]);
EmailCode::purgeExpired();
check('purgeExpired 清掉过期记录', $db->first('SELECT * FROM ly_email_codes WHERE `email`=?', [$e8]) === null);

// ------------------------------------------------------------
group('8. PasswordReset issue()');

// 需要真实用户行（User::create 第一个参数是 email）
$uEmail = tmail('u');
$usedEmails[] = $uEmail;
$uid   = User::create($uEmail, 'OldPass123', '测试用户');
$createdUsers[] = $uid;
check('创建测试用户成功', $uid > 0);

$uRow = User::find($uid);
check('默认 email_verified=0', (int)$uRow['email_verified'] === 0);
check('默认 email_verified_at 为 NULL', $uRow['email_verified_at'] === null);

User::markEmailVerified($uid);
$uRow = User::find($uid);
check('markEmailVerified 置 1', (int)$uRow['email_verified'] === 1);
check('markEmailVerified 写入时间', !empty($uRow['email_verified_at']));

$token = PasswordReset::issue($uid, $uEmail, '127.0.0.1');
check('令牌为 64 位 hex', preg_match('/^[0-9a-f]{64}$/', $token) === 1);

$row = $db->first('SELECT * FROM ly_password_resets WHERE `user_id`=?', [$uid]);
check('库中不存明文令牌', !empty($row) && (string)$row['token_hash'] !== $token);
check('库中存 sha256(明文)', !empty($row) && (string)$row['token_hash'] === hash('sha256', $token));
check('used_at 初始为 NULL', $row['used_at'] === null);
check('expires_at 约为 30 分钟后', abs(strtotime((string)$row['expires_at']) - (time() + PasswordReset::TTL)) <= 5);

// ------------------------------------------------------------
group('9. PasswordReset findValid() / consume()');

$found = PasswordReset::findValid($token);
check('有效令牌可查到记录', is_array($found) && (int)$found['user_id'] === $uid);

check('空令牌返回 null', PasswordReset::findValid('') === null);
check('非法字符令牌返回 null', PasswordReset::findValid('zzzz') === null);
check('随机 64hex 返回 null', PasswordReset::findValid(bin2hex(random_bytes(32))) === null);

PasswordReset::consume($token);
check('consume 后 used_at 非空',
    (string)$db->first('SELECT `used_at` FROM ly_password_resets WHERE `token_hash`=?', [hash('sha256', $token)])['used_at'] !== '');
check('consume 后 findValid 返回 null', PasswordReset::findValid($token) === null);

// 过期
$token2 = PasswordReset::issue($uid, $uEmail);
$db->query('UPDATE ly_password_resets SET `expires_at`=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `token_hash`=?', [hash('sha256', $token2)]);
check('过期令牌 findValid 返回 null', PasswordReset::findValid($token2) === null);

// ------------------------------------------------------------
group('10. PasswordReset 批量作废');

$t1 = PasswordReset::issue($uid, $uEmail);
$t2 = PasswordReset::issue($uid, $uEmail);
check('新链接使旧链接失效（issue 内先作废旧令牌）', PasswordReset::findValid($t1) === null);
check('最新链接有效', PasswordReset::findValid($t2) !== null);

// 手工造第三张有效令牌，再验证 invalidateAllForUser
$t3 = bin2hex(random_bytes(32));
$db->insert('ly_password_resets', [
    'user_id'    => $uid,
    'email'      => $uEmail,
    'token_hash' => hash('sha256', $t3),
    'ip'         => '',
    'expires_at' => date('Y-m-d H:i:s', time() + 1800),
]);
check('手工令牌初始有效', PasswordReset::findValid($t3) !== null);
PasswordReset::invalidateAllForUser($uid);
check('invalidateAllForUser 后 t2 失效', PasswordReset::findValid($t2) === null);
check('invalidateAllForUser 后 t3 失效', PasswordReset::findValid($t3) === null);

// ------------------------------------------------------------
group('11. PasswordReset purgeExpired()');

$t4 = PasswordReset::issue($uid, $uEmail);
$db->query('UPDATE ly_password_resets SET `expires_at`=DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE `token_hash`=?', [hash('sha256', $t4)]);
PasswordReset::purgeExpired();
check('purgeExpired 清掉 2 天前过期的记录',
    $db->first('SELECT * FROM ly_password_resets WHERE `token_hash`=?', [hash('sha256', $t4)]) === null);

// ------------------------------------------------------------
group('12. 修改密码后旧密码失效');

User::resetPassword($uid, 'NewPass456');
$u = User::find($uid);
check('新密码可校验通过', password_verify('NewPass456', (string)$u['password']) === true);
check('旧密码校验失败', password_verify('OldPass123', (string)$u['password']) === false);

// ------------------------------------------------------------
// 清理现场
echo "\n[清理] 删除测试数据\n";
foreach ($usedEmails as $mail) {
    $db->query('DELETE FROM ly_email_codes WHERE `email`=?', [$mail]);
}
foreach ($createdUsers as $u) {
    $db->query('DELETE FROM ly_password_resets WHERE `user_id`=?', [$u]);
    $db->query('DELETE FROM ly_users WHERE `id`=?', [$u]);
}
$db->query("DELETE FROM ly_email_codes WHERE `email` LIKE 'testflow\\_%'");
$db->query("DELETE FROM ly_users WHERE `email` LIKE 'testflow\\_%'");
$db->query("DELETE FROM ly_password_resets WHERE `email` LIKE 'testflow\\_%'");
$left = (int)$db->first("SELECT COUNT(*) AS c FROM ly_users WHERE `email` LIKE 'testflow\\_%'")['c'];
check('测试用户已清理干净', $left === 0);
echo "  ✔ 已清理测试用户与验证码\n";

echo "\n==========================================\n";
echo " 通过: {$pass}   失败: {$fail}\n";
echo "==========================================\n";
exit($fail > 0 ? 1 : 0);
