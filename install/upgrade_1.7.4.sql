-- ============================================================
-- LY云计算 升级脚本：1.7.3 → 1.7.4
-- 内容：商品「每人限购次数」
--
--   1) ly_products 新增 limit_per_user（0 = 不限购）
--   2) ly_orders   新增 idx_user_product_status 组合索引
--      （限购校验按 user_id + product_id + status 统计，避免全表扫）
--
-- 特点：幂等，可重复执行（基于 information_schema 存在性判断）
--
-- 执行方式：
--   mysql -uroot -p 库名 < install/upgrade_1.7.4.sql
-- ============================================================

-- ------------------------------------------------------------
-- 1) ly_products.limit_per_user
-- ------------------------------------------------------------
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ly_products'
    AND COLUMN_NAME  = 'limit_per_user'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `limit_per_user` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''每人限购次数,0=不限购(按已付款订单计)'' AFTER `duration_unit`',
  'SELECT ''ly_products.limit_per_user 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) ly_orders.idx_user_product_status
-- ------------------------------------------------------------
SET @exist := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ly_orders'
    AND INDEX_NAME   = 'idx_user_product_status'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD INDEX `idx_user_product_status` (`user_id`,`product_id`,`status`)',
  'SELECT ''ly_orders.idx_user_product_status 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) 结果回显
-- ------------------------------------------------------------
SELECT CONCAT('升级完成：商品每人限购字段就绪（当前共 ',
              (SELECT COUNT(*) FROM `ly_products` WHERE `limit_per_user` > 0),
              ' 个商品已设置限购）。如需限购请到「商品管理 → 编辑」设置每人限购次数，0 表示不限购。') AS result;
