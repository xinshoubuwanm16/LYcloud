-- ============================================================
-- LY云计算 升级脚本：1.7.5 → 1.7.6
-- 内容：商品对接 MNBT（梦奈宝塔主机系统）实时开通主机
--
--   1) ly_products 新增交付方式与 MNBT 规格字段
--        deliver_mode  1=库存池卡密(默认,与老数据一致) 2=MNBT实时开通 3=人工发货
--        mnbt_prefix   主机账号前缀（订单号后缀自动拼接，保证全局唯一）
--        mnbt_webdx    网页空间 MB
--        mnbt_sqldx    数据库空间 MB
--        mnbt_sizemax  月流量 GB
--        mnbt_type     产品类型 1=CDN 2=主机
--        mnbt_ymbds    最多绑定域名数
--   2) ly_stocks 新增来源标记与 MNBT 账号
--        source        1=预录导入(默认) 2=MNBT实时开通
--        mn_username   MNBT 主机账号（面板账号即此账号）
--   3) ly_orders 新增自动开通跟踪字段
--        deliver_status 0无需/未开始 1开通中 2已开通 3待重试 4开通失败
--        deliver_tries  尝试次数（用于重试上限判定）
--        deliver_error  最近一次失败原因（后台可见，便于排障）
--        mnbt_username  MNBT 主机账号快照（换/删库存行也能查）
--
-- 特点：幂等，可重复执行（基于 information_schema 存在性判断）
--       全部为 ADD COLUMN，不改动既有列，老数据自动取默认值，
--       deliver_mode 默认 1 = 现有「库存池卡密」行为，历史商品零影响。
--
-- 执行方式：
--   mysql -uroot -p 库名 < install/upgrade_1.7.6.sql
-- ============================================================

-- ------------------------------------------------------------
-- 1) ly_products：交付方式与 MNBT 规格
-- ------------------------------------------------------------

-- 1.1 deliver_mode
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'deliver_mode'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `deliver_mode` TINYINT NOT NULL DEFAULT 1 COMMENT ''交付方式 1库存池卡密 2MNBT实时开通 3人工发货'' AFTER `auto_deliver`',
  'SELECT ''ly_products.deliver_mode 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.2 mnbt_prefix
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'mnbt_prefix'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `mnbt_prefix` VARCHAR(16) NOT NULL DEFAULT '''' COMMENT ''MNBT主机账号前缀(订单号后缀自动拼接)'' AFTER `deliver_mode`',
  'SELECT ''ly_products.mnbt_prefix 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.3 mnbt_webdx
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'mnbt_webdx'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `mnbt_webdx` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''MNBT网页空间MB'' AFTER `mnbt_prefix`',
  'SELECT ''ly_products.mnbt_webdx 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.4 mnbt_sqldx
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'mnbt_sqldx'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `mnbt_sqldx` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''MNBT数据库空间MB'' AFTER `mnbt_webdx`',
  'SELECT ''ly_products.mnbt_sqldx 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.5 mnbt_sizemax
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'mnbt_sizemax'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `mnbt_sizemax` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''MNBT月流量GB'' AFTER `mnbt_sqldx`',
  'SELECT ''ly_products.mnbt_sizemax 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.6 mnbt_type
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'mnbt_type'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `mnbt_type` TINYINT NOT NULL DEFAULT 2 COMMENT ''MNBT产品类型 1=CDN 2=主机'' AFTER `mnbt_sizemax`',
  'SELECT ''ly_products.mnbt_type 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.7 mnbt_ymbds
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'mnbt_ymbds'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `mnbt_ymbds` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''MNBT最多绑定域名数'' AFTER `mnbt_type`',
  'SELECT ''ly_products.mnbt_ymbds 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) ly_stocks：来源标记与 MNBT 账号
-- ------------------------------------------------------------

-- 2.1 source
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_stocks' AND COLUMN_NAME = 'source'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_stocks` ADD COLUMN `source` TINYINT NOT NULL DEFAULT 1 COMMENT ''来源 1预录导入 2MNBT实时开通'' AFTER `remark`',
  'SELECT ''ly_stocks.source 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.2 mn_username
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_stocks' AND COLUMN_NAME = 'mn_username'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_stocks` ADD COLUMN `mn_username` VARCHAR(120) NOT NULL DEFAULT '''' COMMENT ''MNBT主机账号(source=2时使用)'' AFTER `source`',
  'SELECT ''ly_stocks.mn_username 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) ly_orders：自动开通跟踪字段
-- ------------------------------------------------------------

-- 3.1 deliver_status
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'deliver_status'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `deliver_status` TINYINT NOT NULL DEFAULT 0 COMMENT ''自动开通状态 0无需/未开始 1开通中 2已开通 3待重试 4开通失败'' AFTER `delivered_at`',
  'SELECT ''ly_orders.deliver_status 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3.2 deliver_tries
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'deliver_tries'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `deliver_tries` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''自动开通尝试次数'' AFTER `deliver_status`',
  'SELECT ''ly_orders.deliver_tries 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3.3 deliver_error
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'deliver_error'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `deliver_error` VARCHAR(255) NOT NULL DEFAULT '''' COMMENT ''最近一次开通失败原因'' AFTER `deliver_tries`',
  'SELECT ''ly_orders.deliver_error 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3.4 mnbt_username
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'mnbt_username'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `mnbt_username` VARCHAR(120) NOT NULL DEFAULT '''' COMMENT ''MNBT主机账号快照'' AFTER `deliver_error`',
  'SELECT ''ly_orders.mnbt_username 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4) 索引：待重试订单扫描（低频，但避免全表扫）
-- ------------------------------------------------------------
SET @exist := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND INDEX_NAME = 'idx_deliver_status'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD INDEX `idx_deliver_status` (`deliver_status`)',
  'SELECT ''ly_orders.idx_deliver_status 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5) 历史数据回填
--    deliver_mode 采用 ADD COLUMN DEFAULT 1，MySQL 对既有行自动填默认值，
--    与「库存池卡密」的既有行为一致，无需额外 UPDATE。
-- ------------------------------------------------------------

-- ------------------------------------------------------------
-- 6) 结果回显
-- ------------------------------------------------------------
SELECT CONCAT(
  '升级完成：MNBT 对接字段就绪。',
  ' 当前商品交付方式分布 —— 库存池卡密 ',
  (SELECT COUNT(*) FROM `ly_products` WHERE `deliver_mode` = 1),
  ' 个 / MNBT实时开通 ',
  (SELECT COUNT(*) FROM `ly_products` WHERE `deliver_mode` = 2),
  ' 个 / 人工发货 ',
  (SELECT COUNT(*) FROM `ly_products` WHERE `deliver_mode` = 3),
  ' 个。',
  ' 如需使用 MNBT 实时开通，请前往「系统设置 → MNBT对接」填写接口地址与密钥，再到「商品管理 → 编辑」把交付方式切换为「MNBT 实时开通」。'
) AS result;
