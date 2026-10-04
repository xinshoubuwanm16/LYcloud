-- ============================================================
--  LY云计算 v1.1.0 → v1.2.0 增量升级脚本
--  新增：余额系统 —— 兑换码、余额流水、订单余额抵扣
--
--  本脚本是幂等的，可重复执行，不会丢数据。
--  执行方式：
--      mysql -ulycloud -p lycloud < install/upgrade_1.2.0.sql
--
--  说明：ly_users.balance 列在 1.0.0 建表时就已存在，此处只做幂等兜底
--        （防止有人的库是在更早的版本建的、或曾被手工删过）。
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) ly_users.balance 幂等兜底（老库可能缺失）
-- ------------------------------------------------------------
SET @db := DATABASE();

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_users'
                   AND COLUMN_NAME  = 'balance');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_users` ADD COLUMN `balance` DECIMAL(10,2) NOT NULL DEFAULT ''0.00'' COMMENT ''余额'' AFTER `nickname`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) ly_orders 增加余额抵扣相关列
--    amount（订单总额）= pay_amount（需支付宝支付）+ balance_paid（余额抵扣）
-- ------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_orders'
                   AND COLUMN_NAME  = 'pay_amount');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `pay_amount` DECIMAL(10,2) NOT NULL DEFAULT ''0.00'' COMMENT ''实际需向支付宝支付的金额'' AFTER `amount`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_orders'
                   AND COLUMN_NAME  = 'balance_paid');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `balance_paid` DECIMAL(10,2) NOT NULL DEFAULT ''0.00'' COMMENT ''余额抵扣金额'' AFTER `pay_amount`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 存量订单：pay_amount 补齐为 amount（此前无余额抵扣概念）
UPDATE `ly_orders` SET `pay_amount` = `amount`
 WHERE `pay_amount` = 0.00 AND `balance_paid` = 0.00 AND `amount` > 0.00;

-- ------------------------------------------------------------
-- 3) 兑换码表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ly_redeem_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash` CHAR(64) NOT NULL COMMENT '兑换码 sha256（不存明文）',
  `code_mask` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '掩码，如 LY7K****4TP9',
  `batch_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '批次号，用于按批作废',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '面额',
  `status` TINYINT NOT NULL DEFAULT '0' COMMENT '0未用 1已用 2已作废',
  `used_by` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '兑换用户ID',
  `used_at` DATETIME NULL DEFAULT NULL COMMENT '兑换时间',
  `expires_at` DATETIME NULL DEFAULT NULL COMMENT '过期时间，NULL=永久有效',
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '生成的管理员ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code_hash` (`code_hash`),
  KEY `idx_status` (`status`),
  KEY `idx_batch` (`batch_no`),
  KEY `idx_used_by` (`used_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='余额兑换码';

-- ------------------------------------------------------------
-- 4) 余额流水表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ly_balance_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `change` DECIMAL(10,2) NOT NULL COMMENT '变动额，正=入账 负=出账',
  `before` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '变动前余额',
  `after` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '变动后余额',
  `type` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'redeem兑换 consume消费 admin人工调账 refund退款',
  `ref_id` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '关联ID（订单ID/兑换码ID）',
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='余额流水';

-- ------------------------------------------------------------
-- 5) 默认配置（INSERT IGNORE：已存在的键不会被覆盖）
-- ------------------------------------------------------------
INSERT IGNORE INTO `ly_settings` (`k`,`v`) VALUES
('balance_enabled',   '1'),
('redeem_enabled',    '1'),
('redeem_min_amount', '1.00'),
('redeem_max_amount', '99999.00');

SELECT '升级完成：ly_redeem_codes / ly_balance_logs 已就绪，ly_orders 已加余额抵扣列' AS result;
