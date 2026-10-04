-- ============================================================
-- LY云计算 1.7.8 → 1.7.9 升级脚本（捐赠支付）
--
-- 内容：
--   1) ly_orders 增加 user_claimed / claim_at 两列（捐赠支付人工核验用）
--   2) 默认配置写入捐赠支付键（已有则跳过）
--
-- 幂等：可重复执行，已存在的结构/配置自动跳过。
-- 执行：mysql -u lycloud -p lycloud < install/upgrade_1.7.9.sql
-- ============================================================

-- ------------------------------------------------------------
-- 1) ly_orders.user_claimed / claim_at
-- ------------------------------------------------------------

-- 1.1 user_claimed
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'user_claimed'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `user_claimed` TINYINT NOT NULL DEFAULT 0 COMMENT ''捐赠支付：用户已声明付款 0/1（1.7.9）'' AFTER `trade_no`',
  'SELECT ''ly_orders.user_claimed 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.2 claim_at
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME = 'claim_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `ly_orders` ADD COLUMN `claim_at` DATETIME NULL DEFAULT NULL COMMENT ''捐赠支付：声明付款时间（1.7.9）'' AFTER `user_claimed`',
  'SELECT ''ly_orders.claim_at 已存在，跳过'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) 捐赠支付默认配置（INSERT IGNORE：已有配置不覆盖）
-- ------------------------------------------------------------
INSERT IGNORE INTO `ly_settings` (`k`, `v`) VALUES
('donate_enabled',        '0'),
('donate_desc',           '扫描下方收款码完成付款，付款后点击「我已完成付款」，管理员核实到账后即为您发货。'),
('donate_qr_ali_path',    ''),
('donate_qr_wechat_path', '');

-- ------------------------------------------------------------
-- 3) 结果回显
-- ------------------------------------------------------------

SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ly_orders' AND COLUMN_NAME IN ('user_claimed','claim_at'))
                                                                          AS `捐赠列就位`,
  (SELECT COUNT(*) FROM `ly_settings` WHERE `k` LIKE 'donate%')           AS `捐赠配置项`,
  (SELECT COUNT(*) FROM `ly_orders` WHERE `pay_channel` = 'donate')       AS `捐赠订单数`,
  (SELECT COUNT(*) FROM `ly_orders` WHERE `pay_channel` = 'donate' AND `user_claimed` = 1 AND `status` = 0)
                                                                          AS `待核验捐赠单`;

SELECT '升级完成：到后台「系统设置 → 捐赠收款码」上传支付宝/微信收款码并启用后，前台下单即默认展示捐赠支付。' AS `提示`;
