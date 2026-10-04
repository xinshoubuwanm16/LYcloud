-- ============================================================
--  LY云计算 - 1.6.1 → 1.7.0 升级脚本
--
--  内容：商品有效期体系
--    1) ly_products 新增 duration_value / duration_unit（商品固定时长：天/周/月/年，0=永久）
--    2) ly_orders 新增 expire_at（支付时按商品有效期起算）/ reminded_at（到期提醒已发）/ disposed_at（管理员标记已删机）
--    3) 存量已支付/已发货订单按当前商品有效期回填 expire_at（四条 UPDATE 按 unit 分别执行）
--
--  说明：
--    - 全部语句可重复执行前的判断已内建（先查 information_schema 再加列的写法在 MySQL 5.7/8 均兼容）；
--      如工具不支持存储过程，直接执行 ALTER 报"Duplicate column"时说明已升级过，忽略即可。
--    - 回填为近似回填：以「当前商品配置的时长」计算历史订单的到期时间；
--      若商品时长后来被修改过，历史订单按新时长回填。
--    - 升级后请到后台「商品管理」逐一核对各商品的有效期（默认 0 = 永久，不影响任何既有订单展示）。
-- ============================================================

-- 1) 商品表：有效期
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'duration_value');
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `duration_value` INT UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''有效期数值,0=永久有效'' AFTER `auto_deliver`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_products' AND COLUMN_NAME = 'duration_unit');
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_products` ADD COLUMN `duration_unit` ENUM(''day'',''week'',''month'',''year'') NOT NULL DEFAULT ''month'' COMMENT ''有效期单位'' AFTER `duration_value`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) 订单表：到期/提醒/删机
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'expire_at');
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `expire_at` DATETIME NULL DEFAULT NULL COMMENT ''到期时间(支付时间+有效期),NULL=永久'' AFTER `delivered_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'reminded_at');
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `reminded_at` DATETIME NULL DEFAULT NULL COMMENT ''到期提醒邮件发送时间,NULL=未提醒'' AFTER `expire_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'disposed_at');
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `disposed_at` DATETIME NULL DEFAULT NULL COMMENT ''管理员标记已删机时间'' AFTER `reminded_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) 到期扫描索引
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND INDEX_NAME = 'idx_expire');
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD INDEX `idx_expire` (`expire_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) 存量订单回填 expire_at（仅已支付/已发货且 paid_at 非空的订单）
UPDATE `ly_orders` o JOIN `ly_products` p ON o.product_id = p.id
  SET o.expire_at = DATE_ADD(o.paid_at, INTERVAL p.duration_value DAY)
  WHERE p.duration_value > 0 AND p.duration_unit = 'day'
    AND o.status IN (1,2) AND o.paid_at IS NOT NULL AND o.expire_at IS NULL;

UPDATE `ly_orders` o JOIN `ly_products` p ON o.product_id = p.id
  SET o.expire_at = DATE_ADD(o.paid_at, INTERVAL p.duration_value WEEK)
  WHERE p.duration_value > 0 AND p.duration_unit = 'week'
    AND o.status IN (1,2) AND o.paid_at IS NOT NULL AND o.expire_at IS NULL;

UPDATE `ly_orders` o JOIN `ly_products` p ON o.product_id = p.id
  SET o.expire_at = DATE_ADD(o.paid_at, INTERVAL p.duration_value MONTH)
  WHERE p.duration_value > 0 AND p.duration_unit = 'month'
    AND o.status IN (1,2) AND o.paid_at IS NOT NULL AND o.expire_at IS NULL;

UPDATE `ly_orders` o JOIN `ly_products` p ON o.product_id = p.id
  SET o.expire_at = DATE_ADD(o.paid_at, INTERVAL p.duration_value YEAR)
  WHERE p.duration_value > 0 AND p.duration_unit = 'year'
    AND o.status IN (1,2) AND o.paid_at IS NOT NULL AND o.expire_at IS NULL;

-- 完成。可选：配置宝塔计划任务每分钟访问 cron/tick（后台「系统设置」复制 URL），
-- 到期提醒邮件将在无人访问时段也能准点发出。
