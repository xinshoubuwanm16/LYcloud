<?php

namespace App\Models;

use App\Database;

/**
 * 库存（宝塔面板信息）模型
 * 核心：防超卖的原子分配
 */
class Stock
{
    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_stocks WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    /**
     * 原子占用一条库存（必须在事务内调用）
     *
     * @param int $productId
     * @return array|null 成功返回库存行，无货返回 null
     */
    public static function allocate(int $productId): ?array
    {
        $db = Database::instance();
        // 行级锁，防止并发超卖
        $row = $db->first(
            'SELECT * FROM ly_stocks WHERE product_id=? AND status=0 ORDER BY id ASC LIMIT 1 FOR UPDATE',
            [$productId]
        );
        if (!$row) {
            return null;
        }
        // 再次确认更新成功（双保险）
        $affected = $db->update(
            'ly_stocks',
            ['status' => 1, 'sold_at' => date('Y-m-d H:i:s')],
            'id=? AND status=0',
            [$row['id']]
        );
        if ($affected === 0) {
            return null;
        }
        $row['status'] = 1;
        return $row;
    }

    /** 库存行绑定订单 */
    public static function bindOrder(int $stockId, int $orderId): void
    {
        Database::instance()->update('ly_stocks', ['order_id' => $orderId], 'id=?', [$stockId]);
    }

    /** 释放库存（订单关闭时） */
    public static function release(int $stockId): void
    {
        Database::instance()->update(
            'ly_stocks',
            ['status' => 0, 'order_id' => 0, 'sold_at' => null],
            'id=?',
            [$stockId]
        );
    }

    /** 分页查询 */
    public static function paginate(int $page, int $perPage, array $filter = []): array
    {
        $db = Database::instance();
        $where = '1=1';
        $params = [];
        if (!empty($filter['product_id'])) {
            $where .= ' AND s.product_id=?';
            $params[] = (int)$filter['product_id'];
        }
        if (isset($filter['status']) && $filter['status'] !== '' && $filter['status'] !== null) {
            $where .= ' AND s.status=?';
            $params[] = (int)$filter['status'];
        }
        if (!empty($filter['keyword'])) {
            $where .= ' AND (s.panel_url LIKE ? OR s.panel_user LIKE ? OR s.remark LIKE ?)';
            $kw = '%' . $filter['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_stocks s WHERE ' . $where, $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT s.*, p.name AS product_name FROM ly_stocks s
             LEFT JOIN ly_products p ON p.id = s.product_id
             WHERE ' . $where . ' ORDER BY s.id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            $params
        );
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * 批量导入库存
     * 支持格式：每行  "面板链接|账号|密码|备注"  或  "面板链接,账号,密码,备注"
     * @return array ['ok'=>n, 'fail'=>m, 'errors'=>[]]
     */
    public static function import(int $productId, string $text): array
    {
        $db = Database::instance();
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $ok = 0;
        $errors = [];
        $rows = [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // 分隔符：竖线 / 逗号 / 制表符
            $parts = preg_split('/\s*[|,\t]\s*/', $line);
            $parts = array_map('trim', $parts);
            $url  = $parts[0] ?? '';
            $user = $parts[1] ?? '';
            $pass = $parts[2] ?? '';
            $note = $parts[3] ?? '';
            if ($url === '' || $user === '' || $pass === '') {
                $errors[] = '第 ' . ($i + 1) . ' 行格式不完整（需 链接|账号|密码）：' . mb_substr($line, 0, 60);
                continue;
            }
            if (mb_strlen($url) > 250) {
                $errors[] = '第 ' . ($i + 1) . ' 行链接过长';
                continue;
            }
            $rows[] = [
                'product_id' => $productId,
                'panel_url'  => $url,
                'panel_user' => mb_substr($user, 0, 110),
                'panel_pass' => mb_substr($pass, 0, 110),
                'remark'     => mb_substr($note, 0, 250),
                'status'     => 0,
            ];
            $ok++;
        }
        if ($rows) {
            $db->transaction(function () use ($db, $rows) {
                foreach ($rows as $r) {
                    $db->insert('ly_stocks', $r);
                }
            });
        }
        return ['ok' => $ok, 'fail' => count($errors), 'errors' => $errors];
    }

    public static function create(array $data): int
    {
        return Database::instance()->insert('ly_stocks', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::instance()->update('ly_stocks', $data, 'id=?', [$id]);
    }

    public static function delete(int $id): void
    {
        Database::instance()->delete('ly_stocks', 'id=? AND status=0', [$id]);
    }

    /** 某商品库存统计 */
    public static function stats(int $productId = 0): array
    {
        $db = Database::instance();
        $w = $productId > 0 ? ' WHERE product_id=' . (int)$productId : '';
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_stocks' . $w);
        $sold  = (int)$db->value('SELECT COUNT(*) FROM ly_stocks' . ($w ? $w . ' AND status=1' : ' WHERE status=1'));
        return ['total' => $total, 'sold' => $sold, 'available' => $total - $sold];
    }
}
