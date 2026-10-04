<?php

namespace App\Models;

use App\Database;

/**
 * 邮箱验证码
 *
 * 安全设计：
 *   - 库中只存 sha256 哈希，不存明文（防数据库/备份泄露直接利用）
 *   - 同邮箱同用途唯一，重复发送为覆盖式更新，天然避免并发多码
 *   - 校验失败累计次数，超过上限立即删除，防暴力枚举
 *   - 校验成功立即删除，防重放
 */
class EmailCode
{
    public const PURPOSE_REGISTER = 'register';
    public const PURPOSE_RESET    = 'reset';

    /** 验证码有效期（秒） */
    public const TTL = 600;
    /** 同邮箱重发冷却（秒） */
    public const COOLDOWN = 60;
    /** 单个验证码最大校验失败次数 */
    public const MAX_ATTEMPTS = 5;

    /**
     * 生成并入库验证码
     *
     * @return array{ok:bool,code:string,msg:string,retry_after:int}
     */
    public static function issue(
        string $email,
        string $purpose = self::PURPOSE_REGISTER,
        string $ip = '',
        int $ttl = self::TTL,
        int $cooldown = self::COOLDOWN
    ): array {
        $email   = strtolower(trim($email));
        $purpose = self::normalizePurpose($purpose);

        if ($email === '') {
            return ['ok' => false, 'code' => '', 'msg' => '邮箱不能为空', 'retry_after' => 0];
        }

        $db = Database::instance();
        self::purgeExpired();

        // 冷却判断：同一邮箱在冷却期内不允许重复发送
        $existing = $db->first(
            'SELECT `id`,`created_at` FROM ly_email_codes WHERE `email`=? AND `purpose`=? LIMIT 1',
            [$email, $purpose]
        );
        if ($existing) {
            $elapsed = time() - strtotime((string)$existing['created_at']);
            if ($elapsed >= 0 && $elapsed < $cooldown) {
                return [
                    'ok'          => false,
                    'code'        => '',
                    'msg'         => '发送过于频繁，请稍后再试',
                    'retry_after' => $cooldown - $elapsed,
                ];
            }
        }

        $code = (string)random_int(100000, 999999);
        $hash = hash('sha256', $code);

        $sql = 'INSERT INTO ly_email_codes (`email`,`purpose`,`code_hash`,`attempts`,`ip`,`expires_at`,`created_at`)
                VALUES (?,?,?,0,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                    `code_hash`=VALUES(`code_hash`),
                    `attempts`=0,
                    `ip`=VALUES(`ip`),
                    `expires_at`=VALUES(`expires_at`),
                    `created_at`=NOW()';
        $db->query($sql, [
            $email,
            $purpose,
            $hash,
            mb_substr($ip, 0, 64),
            date('Y-m-d H:i:s', time() + $ttl),
        ]);

        return ['ok' => true, 'code' => $code, 'msg' => '验证码已生成', 'retry_after' => $cooldown];
    }

    /**
     * 校验验证码
     *
     * @return array{ok:bool,msg:string}
     */
    public static function verify(string $email, string $code, string $purpose = self::PURPOSE_REGISTER): array
    {
        $email   = strtolower(trim($email));
        $code    = trim($code);
        $purpose = self::normalizePurpose($purpose);

        if ($email === '' || $code === '') {
            return ['ok' => false, 'msg' => '请填写邮箱验证码'];
        }

        $db  = Database::instance();
        $row = $db->first(
            'SELECT * FROM ly_email_codes WHERE `email`=? AND `purpose`=? LIMIT 1',
            [$email, $purpose]
        );

        if (!$row) {
            return ['ok' => false, 'msg' => '验证码不存在或已失效，请重新获取'];
        }

        // 过期
        if (strtotime((string)$row['expires_at']) < time()) {
            self::consume($email, $purpose);
            return ['ok' => false, 'msg' => '验证码已过期，请重新获取'];
        }

        // 尝试次数超限
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            self::consume($email, $purpose);
            return ['ok' => false, 'msg' => '验证码错误次数过多，请重新获取'];
        }

        // 定时安全比较
        if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
            $db->query(
                'UPDATE ly_email_codes SET `attempts`=`attempts`+1 WHERE `id`=?',
                [(int)$row['id']]
            );
            $left = self::MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
            if ($left <= 0) {
                self::consume($email, $purpose);
                return ['ok' => false, 'msg' => '验证码错误次数过多，请重新获取'];
            }
            return ['ok' => false, 'msg' => '验证码不正确（还可尝试 ' . $left . ' 次）'];
        }

        // 成功即销毁，防重放
        self::consume($email, $purpose);
        return ['ok' => true, 'msg' => '验证通过'];
    }

    /** 删除指定验证码 */
    public static function consume(string $email, string $purpose = self::PURPOSE_REGISTER): void
    {
        Database::instance()->query(
            'DELETE FROM ly_email_codes WHERE `email`=? AND `purpose`=?',
            [strtolower(trim($email)), self::normalizePurpose($purpose)]
        );
    }

    /** 最近一次发送时间戳；无记录返回 0 */
    public static function lastSentAt(string $email, string $purpose = self::PURPOSE_REGISTER): int
    {
        $row = Database::instance()->first(
            'SELECT `created_at` FROM ly_email_codes WHERE `email`=? AND `purpose`=? LIMIT 1',
            [strtolower(trim($email)), self::normalizePurpose($purpose)]
        );
        return $row ? (int)strtotime((string)$row['created_at']) : 0;
    }

    /** 清理过期记录（惰性调用，避免堆积） */
    public static function purgeExpired(): void
    {
        try {
            Database::instance()->query('DELETE FROM ly_email_codes WHERE `expires_at` < NOW()');
        } catch (\Throwable $e) {
            // 清理失败不影响主流程
        }
    }

    private static function normalizePurpose(string $purpose): string
    {
        return $purpose === self::PURPOSE_RESET ? self::PURPOSE_RESET : self::PURPOSE_REGISTER;
    }
}
