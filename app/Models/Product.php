<?php

namespace App\Models;

use App\Database;

/**
 * 商品模型
 */
class Product
{
    /** 有效期单位白名单 */
    public const DURATION_UNITS = ['day', 'week', 'month', 'year'];

    /** 单位中文映射 */
    public const DURATION_UNIT_TEXT = ['day' => '天', 'week' => '周', 'month' => '个月', 'year' => '年'];

    // ---------------- 交付方式（deliver_mode）----------------
    /** 库存池卡密（预录面板信息，支付后分配） */
    public const DELIVER_STOCK = 1;
    /** MNBT 实时开通（支付后调用 API 开通主机） */
    public const DELIVER_MNBT  = 2;
    /** 纯人工发货 */
    public const DELIVER_MANUAL = 3;

    public const DELIVER_TEXT = [
        self::DELIVER_STOCK  => '库存池卡密',
        self::DELIVER_MNBT   => 'MNBT 实时开通',
        self::DELIVER_MANUAL => '人工发货',
    ];

    /** 归一化交付方式（非法值回落库存池） */
    public static function normalizeDeliverMode(int $mode): int
    {
        return isset(self::DELIVER_TEXT[$mode]) ? $mode : self::DELIVER_STOCK;
    }

    /** 交付方式显示名 */
    public static function deliverModeText(?array $product): string
    {
        $m = self::normalizeDeliverMode((int)($product['deliver_mode'] ?? self::DELIVER_STOCK));
        return self::DELIVER_TEXT[$m];
    }

    /** 该商品是否为 MNBT 实时开通 */
    public static function isMnbt(?array $product): bool
    {
        return $product !== null
            && self::normalizeDeliverMode((int)($product['deliver_mode'] ?? 0)) === self::DELIVER_MNBT;
    }

    /** MNBT 产品类型中文名 */
    public static function mnbtTypeText(?array $product): string
    {
        return (int)($product['mnbt_type'] ?? 2) === 1 ? 'CDN' : '主机';
    }

    /**
     * MNBT 规格摘要，如「网页空间 1024MB / 数据库 200MB / 流量 50GB / 域名 5 个」
     * 用于后台商品列表与订单页快速确认配置
     */
    public static function mnbtSpecText(?array $product): string
    {
        if ($product === null) {
            return '';
        }
        $parts = [];
        $parts[] = '空间 ' . (int)($product['mnbt_webdx'] ?? 0) . 'MB';
        $parts[] = '数据库 ' . (int)($product['mnbt_sqldx'] ?? 0) . 'MB';
        $parts[] = '流量 ' . (int)($product['mnbt_sizemax'] ?? 0) . 'GB/月';
        $parts[] = '域名 ' . (int)($product['mnbt_ymbds'] ?? 0) . ' 个';
        return implode(' / ', $parts);
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM ly_products WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    /**
     * 有效期人类可读文本：「1 个月」「90 天」「永久有效」
     */
    public static function durationText(?array $product): string
    {
        if (!$product) {
            return '永久有效';
        }
        $value = max(0, (int)($product['duration_value'] ?? 0));
        if ($value === 0) {
            return '永久有效';
        }
        $unit = (string)($product['duration_unit'] ?? 'month');
        $unitText = self::DURATION_UNIT_TEXT[$unit] ?? '个月';
        return $value . ' ' . $unitText;
    }

    /**
     * 归一化「每人限购次数」：0 = 不限购，上限 9999
     */
    public static function normalizeLimit(int $value): int
    {
        return max(0, min(9999, $value));
    }

    /**
     * 限购人类可读文本：「每人限购 1 次」「不限购」
     */
    public static function limitText(?array $product): string
    {
        $limit = $product ? self::normalizeLimit((int)($product['limit_per_user'] ?? 0)) : 0;
        return $limit === 0 ? '不限购' : '每人限购 ' . $limit . ' 次';
    }

    /**
     * 用户对该商品「已付款」的订单数（限制次数的计数口径）
     *
     * 口径：已支付(1) + 已发货(2) 计入；待支付/已关闭/已退款均不计入。
     * 因此「拍下不付款」和「超时关单」都不会占用名额。
     */
    public static function purchasedCount(int $userId, int $productId): int
    {
        if ($userId <= 0 || $productId <= 0) {
            return 0;
        }
        return (int)Database::instance()->value(
            'SELECT COUNT(*) FROM ly_orders WHERE user_id=? AND product_id=? AND status IN (?, ?)',
            [$userId, $productId, Order::STATUS_PAID, Order::STATUS_DELIVERED]
        );
    }

    /**
     * 剩余可购次数；不限购返回 null
     */
    public static function remainingQuota(?array $product, int $userId): ?int
    {
        $limit = $product ? self::normalizeLimit((int)($product['limit_per_user'] ?? 0)) : 0;
        if ($limit === 0) {
            return null;
        }
        $bought = self::purchasedCount($userId, (int)($product['id'] ?? 0));
        return max(0, $limit - $bought);
    }

    /**
     * 用户是否还能购买该商品
     *
     * @return array{ok:bool,msg:string,limit:int,bought:int,remaining:?int}
     */
    public static function canPurchase(?array $product, int $userId): array
    {
        $limit = $product ? self::normalizeLimit((int)($product['limit_per_user'] ?? 0)) : 0;
        if ($limit === 0) {
            return ['ok' => true, 'msg' => '', 'limit' => 0, 'bought' => 0, 'remaining' => null];
        }
        if (!$product || (int)($product['id'] ?? 0) <= 0) {
            return ['ok' => false, 'msg' => '商品不存在', 'limit' => $limit, 'bought' => 0, 'remaining' => 0];
        }

        $bought    = self::purchasedCount($userId, (int)$product['id']);
        $remaining = max(0, $limit - $bought);

        if ($remaining <= 0) {
            return [
                'ok'        => false,
                'msg'       => '该商品每人限购 ' . $limit . ' 次，您已购买 ' . $bought . ' 次，无法再次购买',
                'limit'     => $limit,
                'bought'    => $bought,
                'remaining' => 0,
            ];
        }

        return ['ok' => true, 'msg' => '', 'limit' => $limit, 'bought' => $bought, 'remaining' => $remaining];
    }

    /**
     * 按支付时刻起算到期时间（本地时间 Y-m-d H:i:s）
     * 有效期为 0（永久）返回 null。月/年采用日历语义（PHP 原生 +N month/year，自动处理大小月与闰年）。
     */
    public static function expireAt(?array $product, string $paidAt): ?string
    {
        $value = $product ? max(0, (int)($product['duration_value'] ?? 0)) : 0;
        if ($value === 0) {
            return null;
        }
        $unit = (string)($product['duration_unit'] ?? 'month');
        if (!in_array($unit, self::DURATION_UNITS, true)) {
            $unit = 'month';
        }
        $ts = strtotime($paidAt);
        if ($ts === false) {
            return null;
        }
        $expire = strtotime('+' . $value . ' ' . $unit, $ts);
        return $expire !== false ? date('Y-m-d H:i:s', $expire) : null;
    }

    /** 规范化有效期输入（非法单位归一为 month，数值 0-999） */
    public static function normalizeDuration(int $value, string $unit): array
    {
        return [
            'duration_value' => max(0, min(999, $value)),
            'duration_unit'  => in_array($unit, self::DURATION_UNITS, true) ? $unit : 'month',
        ];
    }

    /** 上架商品列表；$categoryId：0=不限 / 正整数=仅该分类（隐藏分类传入时也能筛出商品） */
    public static function active(int $limit = 0, int $categoryId = 0): array
    {
        $sql = 'SELECT * FROM ly_products WHERE status=1';
        $params = [];
        if ($categoryId > 0) {
            $sql .= ' AND category_id=?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY sort DESC, id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $rows = Database::instance()->select($sql, $params);
        foreach ($rows as &$r) {
            $r['stock_count'] = self::stockCount((int)$r['id']);
        }
        return $rows;
    }

    /** 后台列表（含下架）；$categoryId：''=全部 / 0=未分类 / 正整数=指定分类 */
    public static function paginate(int $page, int $perPage = 20, string $keyword = '', int|string $categoryId = ''): array
    {
        $db = Database::instance();
        $where = '1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND name LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        if ($categoryId !== '') {
            $where .= ' AND category_id=?';
            $params[] = max(0, (int)$categoryId);
        }
        $total = (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE ' . $where, $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->select(
            'SELECT * FROM ly_products WHERE ' . $where . ' ORDER BY sort DESC, id DESC LIMIT ' . (int)$offset . ',' . (int)$perPage,
            $params
        );
        foreach ($rows as &$r) {
            $r['stock_count'] = self::stockCount((int)$r['id']);
            $r['sold_count']  = self::soldCount((int)$r['id']);
        }
        return ['total' => $total, 'rows' => $rows];
    }

    /** 可用库存数 */
    public static function stockCount(int $productId): int
    {
        return (int)Database::instance()->value(
            'SELECT COUNT(*) FROM ly_stocks WHERE product_id=? AND status=0',
            [$productId]
        );
    }

    public static function soldCount(int $productId): int
    {
        return (int)Database::instance()->value(
            'SELECT COUNT(*) FROM ly_stocks WHERE product_id=? AND status=1',
            [$productId]
        );
    }

    public static function create(array $data): int
    {
        return Database::instance()->insert('ly_products', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::instance()->update('ly_products', $data, 'id=?', [$id]);
    }

    public static function delete(int $id): void
    {
        $db = Database::instance();
        $db->transaction(function () use ($db, $id) {
            // 已售库存保留（保证历史订单可查），未售库存删除
            $db->delete('ly_stocks', 'product_id=? AND status=0', [$id]);
            $db->delete('ly_products', 'id=?', [$id]);
        });
    }

    /** 累加销量 */
    public static function increaseSales(int $id, int $n = 1): void
    {
        Database::instance()->query('UPDATE ly_products SET sales = sales + ? WHERE id=?', [$n, $id]);
    }
}
