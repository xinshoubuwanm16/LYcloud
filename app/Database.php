<?php

namespace App;

use PDO;
use PDOException;

/**
 * 数据库封装（PDO + 预处理，防 SQL 注入）
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct(array $cfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            (int)($cfg['port'] ?? 3306),
            $cfg['database']
        );

        try {
            $this->pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, sql_mode='NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (PDOException $e) {
            if (LY_DEBUG) {
                throw $e;
            }
            http_response_code(500);
            exit('数据库连接失败，请检查 config/config.php 配置。');
        }
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self(config('db'));
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** 执行 SQL，返回 PDOStatement */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** 查询多行 */
    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** 查询单行 */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 查询单值 */
    public function value(string $sql, array $params = [])
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 插入，返回自增 ID */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->query($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /** 更新，返回影响行数 */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = '`' . $col . '`=?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . $where;
        $stmt = $this->query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /** 删除 */
    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->query('DELETE FROM `' . $table . '` WHERE ' . $where, $params)->rowCount();
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** 事务包装 */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }
}
