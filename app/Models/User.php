<?php

namespace App\Models;

use App\Database;

/**
 * 用户模型
 */
class User
{
    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_users WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_users WHERE email=? LIMIT 1', [$email]) ?: null;
    }

    public static function create(string $email, string $password, string $nickname = '', bool $verified = false): int
    {
        return Database::instance()->insert('ly_users', [
            'email'             => $email,
            'password'          => \App\Auth::hash($password),
            'nickname'          => $nickname !== '' ? $nickname : explode('@', $email)[0],
            'status'            => 1,
            'email_verified'    => $verified ? 1 : 0,
            'email_verified_at' => $verified ? date('Y-m-d H:i:s') : null,
        ]);
    }

    /** 标记邮箱已验证 */
    public static function markEmailVerified(int $id): void
    {
        Database::instance()->update('ly_users', [
            'email_verified'    => 1,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
    }

    public static function paginate(int $page, int $perPage, string $keyword = ''): array
    {
        $db = Database::instance();
        $where = '1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (email LIKE ? OR nickname LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_users WHERE ' . $where, $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT u.*, (SELECT COUNT(*) FROM ly_orders o WHERE o.user_id=u.id) AS order_count,
                    (SELECT IFNULL(SUM(o2.amount),0) FROM ly_orders o2 WHERE o2.user_id=u.id AND o2.status IN (1,2)) AS total_paid
             FROM ly_users u WHERE ' . $where . ' ORDER BY u.id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            $params
        );
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * 读取用户余额（两位小数字符串）
     * 直接查库不读缓存，避免同请求内刚变动过却读到旧值
     */
    public static function balanceOf(int $id): string
    {
        $v = Database::instance()->value('SELECT balance FROM ly_users WHERE id=? LIMIT 1', [$id]);
        return \App\Models\BalanceLog::normalize($v);
    }

    /**
     * 失效 Auth::user() 的静态缓存
     *
     * Auth::user() 用 static 变量缓存了整行用户数据，余额变动后若不清缓存，
     * 同一请求内后续读到的仍是旧余额。
     */
    public static function flushCache(): void
    {
        \App\Auth::flushUserCache();
    }

    public static function updateLastLogin(int $id): void
    {
        Database::instance()->update('ly_users', [
            'last_login_ip' => client_ip(),
            'last_login_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
    }

    public static function toggleStatus(int $id): int
    {
        $db = Database::instance();
        $u = self::find($id);
        if (!$u) {
            return 0;
        }
        $new = (int)$u['status'] === 1 ? 0 : 1;
        $db->update('ly_users', ['status' => $new], 'id=?', [$id]);
        return $new;
    }

    public static function resetPassword(int $id, string $newPassword): void
    {
        Database::instance()->update('ly_users', ['password' => \App\Auth::hash($newPassword)], 'id=?', [$id]);
    }

    public static function count(): int
    {
        return (int)Database::instance()->value('SELECT COUNT(*) FROM ly_users');
    }
}
