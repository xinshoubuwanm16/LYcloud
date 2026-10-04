<?php

namespace App\Models;

use App\Database;

/**
 * 管理员模型
 */
class Admin
{
    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_admins WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_admins WHERE username=? LIMIT 1', [$username]) ?: null;
    }

    public static function create(string $username, string $password, string $realName = ''): int
    {
        return Database::instance()->insert('ly_admins', [
            'username'  => $username,
            'password'  => \App\Auth::hash($password),
            'real_name' => $realName,
            'status'    => 1,
        ]);
    }

    public static function updateLastLogin(int $id): void
    {
        Database::instance()->update('ly_admins', [
            'last_login_ip' => client_ip(),
            'last_login_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
    }

    public static function updatePassword(int $id, string $newPassword): void
    {
        Database::instance()->update('ly_admins', ['password' => \App\Auth::hash($newPassword)], 'id=?', [$id]);
    }
}
