<?php

namespace App\Models;

use App\Database;

/**
 * 找回密码令牌
 *
 * 安全设计：
 *   - 库中只存 sha256(令牌)，明文只出现在邮件链接里
 *   - 一次性使用（used_at 非空即失效）
 *   - 有有效期（默认 30 分钟）
 *   - 改密成功后作废该用户全部未用令牌
 *   - 重新申请时也作废旧令牌
 */
class PasswordReset
{
    /** 令牌有效期（秒） */
    public const TTL = 1800;

    /**
     * 生成令牌，返回明文（仅此一次可见，用于拼邮件链接）
     */
    public static function issue(int $userId, string $email, string $ip = '', int $ttl = self::TTL): string
    {
        $db     = Database::instance();
        $raw    = bin2hex(random_bytes(32)); // 64 位 hex
        $hash   = hash('sha256', $raw);

        self::purgeExpired();

        // 先作废该用户所有未使用的旧令牌，避免多链接同时可用
        self::invalidateAllForUser($userId);

        $db->insert('ly_password_resets', [
            'user_id'    => $userId,
            'email'      => strtolower(trim($email)),
            'token_hash' => $hash,
            'ip'         => mb_substr($ip, 0, 64),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);

        return $raw;
    }

    /**
     * 校验令牌，有效则返回记录行，否则 null
     */
    public static function findValid(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !ctype_xdigit($rawToken)) {
            return null;
        }

        $row = Database::instance()->first(
            'SELECT * FROM ly_password_resets WHERE `token_hash`=? LIMIT 1',
            [hash('sha256', $rawToken)]
        );

        if (!$row) {
            return null;
        }
        if ($row['used_at'] !== null) {
            return null; // 已使用
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return null; // 已过期
        }

        return $row;
    }

    /** 标记令牌已使用 */
    public static function consume(string $rawToken): void
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return;
        }
        Database::instance()->query(
            'UPDATE ly_password_resets SET `used_at`=NOW() WHERE `token_hash`=? AND `used_at` IS NULL',
            [hash('sha256', $rawToken)]
        );
    }

    /** 作废某用户全部未使用令牌 */
    public static function invalidateAllForUser(int $userId): void
    {
        Database::instance()->query(
            'UPDATE ly_password_resets SET `used_at`=NOW() WHERE `user_id`=? AND `used_at` IS NULL',
            [$userId]
        );
    }

    /** 清理过期记录 */
    public static function purgeExpired(): void
    {
        try {
            Database::instance()->query(
                'DELETE FROM ly_password_resets WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );
        } catch (\Throwable $e) {
            // 清理失败不影响主流程
        }
    }
}
