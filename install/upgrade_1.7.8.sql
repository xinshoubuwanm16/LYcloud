-- ============================================================
-- LY云计算 升级脚本：1.7.7 → 1.7.8
-- 内容：商品分类体系
--
--   1) 新建 ly_categories 商品分类表
--        name        分类名称
--        slug        英文标识（URL/锚点用，小写字母数字短横线，自动生成并去重）
--        icon        内置 SVG 图标键（空=不显示图标）
--        color       强调色 HEX（空=跟随主题粉）
--        description 分类描述（前台分类入口副标题）
--        sort        排序，越大越靠前
--        status      1 启用 / 0 隐藏
--   2) ly_products 新增所属分类
--        category_id 分类 ID，0 = 未分类（默认值，历史商品零影响）
--        并补 idx_category 索引，前台按分类筛选走索引
--
-- 特点：幂等，可重复执行（表 / 列 / 索引均基于 information_schema 存在性判断）
--       不新建表时不会报错；不改动任何既有列，老数据自动取默认值，
--       category_id 默认 0 = 「未分类」，历史商品展示与下单行为完全不变。
--
-- 执行方式：
--   mysql -uroot -p 库名 < install/upgrade_1.7.8.sql
-- ============================================================

-- ------------------------------------------------------------
-- 1) ly_categories：商品分类表
-- ------------------------------------------------------------

SET @exist := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_categories'
);
SET @sql := IF(@exist = 0,
  'CREATE TABLE `ly_categories` (
     `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
     `name` VARCHAR(60) NOT NULL COMMENT ''分类名称'',
     `slug` VARCHAR(60) NOT NULL DEFAULT '''' COMMENT ''英文标识(用于URL/锚点,小写字母数字短横线)'',
     `icon` VARCHAR(60) NOT NULL DEFAULT '''' COMMENT ''图标名(内置SVG图标键,空=无图标)'',
     `color` VARCHAR(16) NOT NULL DEFAULT '''' COMMENT ''强调色 HEX(空=跟随主题粉)'',
     `description` VARCHAR(255) NOT NULL DEFAULT '''' COMMENT ''分类描述(前台分类入口副标题)'',
     `sort` INT NOT NULL DEFAULT ''0'' COMMENT ''排序,越大越靠前'',
     `status` TINYINT NOT NULL DEFAULT ''1'' COMMENT ''1启用 0隐藏'',
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     KEY `idx_status_sort` (`status`,`sort`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=''商品分类''',
  'SELECT ''ly_categories 表已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) ly_products：所属分类
-- ------------------------------------------------------------

-- 2.1 category_id
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'category_id'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''所属分类ID,0=未分类'' AFTER `spec`',
  'SELECT ''ly_products.category_id 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.2 idx_category 索引
SET @exist := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND INDEX_NAME = 'idx_category'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD INDEX `idx_category` (`category_id`)',
  'SELECT ''ly_products.idx_category 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) 结果回显
-- ------------------------------------------------------------

SELECT
  (SELECT COUNT(*) FROM `ly_categories`)                                   AS `分类总数`,
  (SELECT COUNT(*) FROM `ly_categories` WHERE `status` = 1)                AS `启用分类`,
  (SELECT COUNT(*) FROM `ly_products` WHERE `category_id` = 0)             AS `未分类商品`,
  (SELECT COUNT(*) FROM `ly_products`)                                     AS `商品总数`;

SELECT '升级完成：可在后台「商品管理 → 分类管理」创建分类，并给商品选择所属分类。' AS `提示`;
