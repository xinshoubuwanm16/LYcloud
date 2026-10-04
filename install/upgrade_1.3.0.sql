-- ============================================================
--  LY云计算 v1.2.0 → v1.3.0 增量升级脚本
--  新增：优惠券系统 —— 券模板、适用商品、用户券包、订单券减免
--
--  本脚本是幂等的，可重复执行，不会丢数据。
--  执行方式：
--      mysql -ulycloud -p lycloud < install/upgrade_1.3.0.sql
--
--  金额链升级为：
--      amount（订单总额） = coupon_discount（券减免）
--                         + balance_paid（余额抵扣）
--                         + pay_amount（需支付宝支付）
-- ============================================================

SET NAMES utf8mb4;

SET @db := DATABASE();

-- ------------------------------------------------------------
-- 1) ly_orders 增加优惠券相关列
-- ------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_orders'
                   AND COLUMN_NAME  = 'coupon_id');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `coupon_id` INT UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''使用的优惠券模板ID，0=未用券'' AFTER `balance_paid`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_orders'
                   AND COLUMN_NAME  = 'coupon_discount');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `coupon_discount` DECIMAL(10,2) NOT NULL DEFAULT ''0.00'' COMMENT ''优惠券减免金额'' AFTER `coupon_id`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ly_orders.coupon_id 索引（幂等兜底）
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_orders'
                   AND INDEX_NAME   = 'idx_coupon');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_orders` ADD KEY `idx_coupon` (`coupon_id`)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 存量订单：券减免补 0（此前无优惠券概念），保持恒等式成立
UPDATE `ly_orders` SET `coupon_discount` = 0.00 WHERE `coupon_discount` IS NULL;

-- ------------------------------------------------------------
-- 2) 优惠券模板表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ly_coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '券名称，如「新客立减10元」',
  `code` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '公开券码（仅领券中心需要，后台定向发放可留空）',
  `type` VARCHAR(10) NOT NULL DEFAULT 'reduce' COMMENT '优惠类型 reduce满减 discount折扣',
  `value` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT 'reduce=减免金额；discount=折扣率（如0.85表示85折）',
  `min_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '使用门槛：订单满该金额才可用；0=无门槛',
  `max_discount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '折扣券最高减免上限；0=不限',
  `scope` TINYINT NOT NULL DEFAULT '0' COMMENT '适用范围 0全场通用 1指定商品',
  `per_user_limit` TINYINT UNSIGNED NOT NULL DEFAULT '1' COMMENT '每人最多可使用次数',
  `received_limit` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '领券中心总发放上限；0=不限',
  `received_count` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '已通过领券中心领取的数量',
  `used_count` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '已使用次数',
  `claimable` TINYINT NOT NULL DEFAULT '0' COMMENT '是否允许前台自主领取 0否 1是',
  `status` TINYINT NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `start_at` DATETIME NULL DEFAULT NULL COMMENT '生效时间；NULL=立即生效',
  `expires_at` DATETIME NULL DEFAULT NULL COMMENT '失效时间；NULL=永久有效',
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建的管理员ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_claimable` (`claimable`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优惠券模板';

-- ------------------------------------------------------------
-- 3) 优惠券适用商品表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ly_coupon_scopes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_coupon_product` (`coupon_id`,`product_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优惠券适用商品';

-- ------------------------------------------------------------
-- 4) 用户优惠券表（券包实例）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ly_user_coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'admin' COMMENT 'admin后台发放 claim自主领取',
  `status` TINYINT NOT NULL DEFAULT '0' COMMENT '0未使用 1已使用 2已失效',
  `order_id` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '使用时关联的订单ID',
  `used_at` DATETIME NULL DEFAULT NULL COMMENT '使用时间',
  `expires_at` DATETIME NULL DEFAULT NULL COMMENT '过期时间（自券模板快照），NULL=永久',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_coupon` (`coupon_id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户优惠券（券包）';

-- ------------------------------------------------------------
-- 5) 默认配置（INSERT IGNORE：已存在的键不会被覆盖）
-- ------------------------------------------------------------
INSERT IGNORE INTO `ly_settings` (`k`,`v`) VALUES
('coupon_enabled',       '1'),
('coupon_claim_enabled', '1');

SELECT '升级完成：ly_coupons / ly_coupon_scopes / ly_user_coupons 已就绪，ly_orders 已加优惠券列' AS result;
