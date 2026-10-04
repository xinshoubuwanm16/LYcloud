<?php

namespace App\Models;

use App\Database;

/**
 * 商品分类模型
 *
 * 设计要点：
 *   - 单层分类（不做多级），一个商品属一个分类；category_id=0 表示「未分类」
 *   - slug 用于 URL 友好锚点，留空自动由名称生成；非中文名转写失败时回落 `cat-{id}`
 *   - 删除保护：分类下仍有商品时默认拒绝删除，避免商品突然「未分类」找不到；
 *     传 force=true 可强制删除，同时把旗下商品 category_id 归零
 *   - status=0 的分类前台不展示入口，但已属该分类的商品在「全部商品」页仍可见
 *
 * 状态：1 启用 / 0 隐藏
 */
class Category
{
    public const STATUS_OFF = 0;
    public const STATUS_ON  = 1;

    /** 未分类的占位 ID */
    public const NONE = 0;

    /** 名称长度上限 */
    public const MAX_NAME = 60;

    public const MAX_SLUG = 60;

    public const MAX_DESC = 255;

    /**
     * 内置图标白名单（键 => 前台内联 SVG 路径）
     *
     * 仅允许这些键落库，避免管理端传入任意字符串后被直接当作 SVG 渲染（XSS 风险）。
     * 图标路径均为 24×24 viewBox 的线性图标，与站点既有风格一致。
     */
    public const ICONS = [
        'server'  => '<rect x="3" y="4" width="18" height="7" rx="1"/><rect x="3" y="13" width="18" height="7" rx="1"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'cloud'   => '<path d="M17.5 19a4.5 4.5 0 0 0 .5-8.97A6 6 0 0 0 6.2 9.4 4.5 4.5 0 0 0 7 19h10.5z"/>',
        'bolt'    => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
        'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 2.5 15.4 0 18M12 3c-2.5 2.6-2.5 15.4 0 18"/>',
        'shield'  => '<path d="M12 3l7 3v6c0 4.4-3 8.3-7 9-4-0.7-7-4.6-7-9V6l7-3z"/>',
        'card'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'gift'    => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 12h18M12 8v13M12 8S9.5 3 7.5 4.5 9 8 12 8zM12 8s2.5-5 4.5-3.5S15 8 12 8z"/>',
        'star'    => '<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 20.9l1.1-6.5L2.6 9.8l6.5-.9L12 3z"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'tag'     => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'chip'    => '<rect x="7" y="7" width="10" height="10" rx="1.5"/><path d="M9 3v4M15 3v4M9 17v4M15 17v4M3 9h4M3 15h4M17 9h4M17 15h4"/>',
        'database'=> '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'fire'    => '<path d="M12 3c3 4 6 6 6 10a6 6 0 0 1-12 0c0-2 1-3.5 2.5-5C9.5 9.5 10 8 10 6c1 .5 2 1.5 2 3 0-2 1-4 0-6z"/>',
        'heart'   => '<path d="M12 20s-7-4.6-7-9.5A4 4 0 0 1 12 7a4 4 0 0 1 7 3.5C19 15.4 12 20 12 20z"/>',
        'cube'    => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
        'layer'   => '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5M3 17l9 5 9-5"/>',
    ];

    // ============================================================
    // 归一化与校验（纯函数，不落库）
    // ============================================================

    /** 归一化图标键（不在白名单则置空，杜绝任意字符串落库后被当 SVG 渲染） */
    public static function normalizeIcon(string $icon): string
    {
        $icon = strtolower(trim($icon));
        return isset(self::ICONS[$icon]) ? $icon : '';
    }

    /**
     * 归一化强调色：只接受 #rgb / #rrggbb，非法值置空（表示跟随主题粉）
     */
    public static function normalizeColor(string $color): string
    {
        $color = strtolower(trim($color));
        if ($color === '') {
            return '';
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $color)) {
            return $color;
        }
        return '';
    }

    /**
     * 归一化名称：去首尾空白、折叠内部连续空白、截断到上限
     */
    public static function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        return mb_substr($name, 0, self::MAX_NAME);
    }

    /**
     * 由名称生成 slug 基串
     *
     * - 纯 ASCII 字母数字：转小写、空格与下划线转短横线
     * - 含中文等非 ASCII：转写失败，返回空（由调用方回落 `cat-{id}`）
     */
    public static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        // 保留字母数字，其余（空格/下划线/标点）统一转短横线
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        $s = preg_replace('/-+/', '-', $s) ?? '';
        $s = substr($s, 0, self::MAX_SLUG);
        return trim($s, '-');
    }

    /**
     * 生成在该库中唯一的 slug
     *
     * 优先用传入值；为空或纯非 ASCII 时回落 `cat-{id}`；
     * 若已存在同 slug 则追加 `-2` `-3` …，直到不冲突。
     *
     * @param string   $slug     期望的 slug（可空）
     * @param string   $name     分类名称（用于自动生成）
     * @param int      $id       当前分类 ID（编辑时排除自身）
     * @param int|null $insertId 新建场景下已插入的自增 ID（用于回落命名）
     */
    public static function uniqueSlug(string $slug, string $name, int $id = 0, ?int $insertId = null): string
    {
        $db = Database::instance();

        $base = self::slugify($slug);
        if ($base === '') {
            $base = self::slugify($name);
        }
        if ($base === '') {
            // 名称全是中文等无法转写的字符：用 ID 兜底，保证仍有稳定标识
            $fallbackId = $insertId ?? $id;
            $base = 'cat-' . max(1, (int)$fallbackId);
        }

        $candidate = $base;
        $n = 1;
        while (true) {
            $exists = (int)$db->value(
                'SELECT COUNT(*) FROM ly_categories WHERE slug=? AND id<>?',
                [$candidate, $id]
            );
            if ($exists === 0) {
                return $candidate;
            }
            $n++;
            $suffix = '-' . $n;
            $candidate = mb_substr($base, 0, self::MAX_SLUG - strlen($suffix)) . $suffix;
        }
    }

    /**
     * 校验分类输入
     *
     * @param array $data name/slug/status 等
     * @return string ''=合法，否则错误文案
     */
    public static function validate(array $data): string
    {
        $name = (string)($data['name'] ?? '');
        if (self::normalizeName($name) === '') {
            return '分类名称不能为空';
        }
        return '';
    }

    /** 归一化状态 */
    public static function normalizeStatus(int $status): int
    {
        return $status === self::STATUS_ON ? self::STATUS_ON : self::STATUS_OFF;
    }

    // ============================================================
    // 查询
    // ============================================================

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::instance()->first('SELECT * FROM ly_categories WHERE id=? LIMIT 1', [$id]) ?: null;
    }

    /**
     * 分类列表
     *
     * @param bool $onlyActive 仅启用（前台用）
     */
    public static function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM ly_categories';
        if ($onlyActive) {
            $sql .= ' WHERE status=1';
        }
        $sql .= ' ORDER BY sort DESC, id ASC';
        return Database::instance()->select($sql);
    }

    /**
     * 前台分类入口数据
     *
     * 只返回启用中的分类，并附带该分类下「已上架」商品数量；
     * 商品数为 0 的分类默认不展示入口（避免点进去空页），可传 $showEmpty=true 放开。
     */
    public static function activeWithCount(bool $showEmpty = false): array
    {
        $db = Database::instance();
        $rows = $db->select(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM ly_products p
                      WHERE p.category_id = c.id AND p.status = 1) AS product_count
               FROM ly_categories c
              WHERE c.status = 1
              ORDER BY c.sort DESC, c.id ASC'
        );
        foreach ($rows as &$r) {
            $r['product_count'] = (int)$r['product_count'];
        }
        unset($r);

        if (!$showEmpty) {
            $rows = array_values(array_filter($rows, static function ($r) {
                return $r['product_count'] > 0;
            }));
        }
        return $rows;
    }

    /** 分类下商品数（含下架，用于后台删除保护判断） */
    public static function useCount(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        return (int)Database::instance()->value(
            'SELECT COUNT(*) FROM ly_products WHERE category_id=?',
            [$id]
        );
    }

    /** 分类下已上架商品数 */
    public static function activeUseCount(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        return (int)Database::instance()->value(
            'SELECT COUNT(*) FROM ly_products WHERE category_id=? AND status=1',
            [$id]
        );
    }

    /** 统计总览（后台列表页顶部） */
    public static function stats(): array
    {
        $db = Database::instance();
        return [
            'total'    => (int)$db->value('SELECT COUNT(*) FROM ly_categories'),
            'active'   => (int)$db->value('SELECT COUNT(*) FROM ly_categories WHERE status=1'),
            'products' => (int)$db->value('SELECT COUNT(*) FROM ly_products'),
            'uncategorized' => (int)$db->value('SELECT COUNT(*) FROM ly_products WHERE category_id=0'),
        ];
    }

    /** 取一批商品的分类映射 [category_id => category]，供列表批量展示，避免 N+1 */
    public static function mapByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        })));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);
        $rows = Database::instance()->select('SELECT * FROM ly_categories WHERE id IN (' . $in . ')');
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = $r;
        }
        return $map;
    }

    // ============================================================
    // 写入
    // ============================================================

    /**
     * 新建分类
     *
     * 先插入取得 ID，再按 ID 补唯一 slug（含「纯中文名回落 cat-{id}」的场景），
     * 两步在同一事务内完成，避免出现 slug 为空的中间态被前台读到。
     */
    public static function create(array $data): int
    {
        $db = Database::instance();
        $id = 0;

        $db->transaction(function () use ($db, $data, &$id) {
            $row = [
                'name'        => self::normalizeName((string)($data['name'] ?? '')),
                'slug'        => '',   // 占位，下一步补齐
                'icon'        => self::normalizeIcon((string)($data['icon'] ?? '')),
                'color'       => self::normalizeColor((string)($data['color'] ?? '')),
                'description' => mb_substr((string)($data['description'] ?? ''), 0, self::MAX_DESC),
                'sort'        => (int)($data['sort'] ?? 0),
                'status'      => self::normalizeStatus((int)($data['status'] ?? self::STATUS_ON)),
            ];
            $id = $db->insert('ly_categories', $row);

            $slug = self::uniqueSlug(
                (string)($data['slug'] ?? ''),
                $row['name'],
                0,
                $id
            );
            $db->update('ly_categories', ['slug' => $slug], 'id=?', [$id]);
        });

        return $id;
    }

    /**
     * 更新分类
     */
    public static function update(int $id, array $data): int
    {
        $db = Database::instance();
        $current = self::find($id);
        if (!$current) {
            return 0;
        }

        $row = [
            'name'        => self::normalizeName((string)($data['name'] ?? $current['name'])),
            'icon'        => self::normalizeIcon((string)($data['icon'] ?? $current['icon'])),
            'color'       => self::normalizeColor((string)($data['color'] ?? $current['color'])),
            'description' => mb_substr((string)($data['description'] ?? $current['description']), 0, self::MAX_DESC),
            'sort'        => (int)($data['sort'] ?? $current['sort']),
            'status'      => self::normalizeStatus((int)($data['status'] ?? $current['status'])),
        ];
        $row['slug'] = self::uniqueSlug(
            (string)($data['slug'] ?? $current['slug']),
            $row['name'],
            $id,
            $id
        );

        return $db->update('ly_categories', $row, 'id=?', [$id]);
    }

    /**
     * 删除分类
     *
     * 默认拒绝删除「仍有商品」的分类；force=true 时强制删除并把旗下商品置为未分类。
     * 两种情况下都不动商品本身（不级联删商品），避免误删造成数据丢失。
     *
     * @return array{ok:bool,msg:string,affected:int}
     */
    public static function delete(int $id, bool $force = false): array
    {
        $db = Database::instance();
        $cat = self::find($id);
        if (!$cat) {
            return ['ok' => false, 'msg' => '分类不存在', 'affected' => 0];
        }

        $used = self::useCount($id);
        if ($used > 0 && !$force) {
            return [
                'ok'    => false,
                'msg'   => '该分类下仍有 ' . $used . ' 个商品，请先改为其他分类；'
                    . '如确认删除，请勾选「同时把旗下商品置为未分类」后重试',
                'affected' => 0,
            ];
        }

        $affected = 0;
        $db->transaction(function () use ($db, $id, $used, &$affected) {
            if ($used > 0) {
                $affected = (int)$db->update(
                    'ly_products',
                    ['category_id' => self::NONE],
                    'category_id=?',
                    [$id]
                );
            }
            $db->delete('ly_categories', 'id=?', [$id]);
        });

        return ['ok' => true, 'msg' => '分类已删除', 'affected' => $affected];
    }

    /** 切换启用/隐藏 */
    public static function toggle(int $id): int
    {
        $cat = self::find($id);
        if (!$cat) {
            return 0;
        }
        $status = (int)$cat['status'] === self::STATUS_ON ? self::STATUS_OFF : self::STATUS_ON;
        Database::instance()->update('ly_categories', ['status' => $status], 'id=?', [$id]);
        return $status;
    }

    // ============================================================
    // 展示辅助（纯函数）
    // ============================================================

    /** 分类显示名（未分类返回占位文案） */
    public static function nameOf(?array $cat, string $fallback = '未分类'): string
    {
        if (!$cat || trim((string)($cat['name'] ?? '')) === '') {
            return $fallback;
        }
        return (string)$cat['name'];
    }

    /** 取内置图标 SVG 路径（不在白名单返回空串） */
    public static function iconPath(string $icon): string
    {
        $icon = strtolower(trim($icon));
        return self::ICONS[$icon] ?? '';
    }

    /**
     * 分类徽标的行内样式（强调色），空色返回空串表示跟随主题
     */
    public static function colorStyle(?array $cat): string
    {
        $color = self::normalizeColor((string)($cat['color'] ?? ''));
        if ($color === '') {
            return '';
        }
        return '--cat-color:' . $color . ';';
    }

    /** 图标键是否合法（供后台表单回显用） */
    public static function iconExists(string $icon): bool
    {
        return isset(self::ICONS[strtolower(trim($icon))]);
    }
}
