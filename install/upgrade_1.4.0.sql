-- ============================================================
--  LY云计算 升级脚本 1.3.0 -> 1.4.0（易支付通道）
--  幂等设计：可重复执行，不会破坏已有数据
--  执行方式：宝塔面板 phpMyAdmin 导入，或 mysql 命令行 source
-- ============================================================

-- 1. 新增系统配置（INSERT IGNORE 保证幂等，且不覆盖已修改的值）
INSERT IGNORE INTO `ly_settings` (`k`,`v`) VALUES
('yipay_switch',     '0'),
('yipay_api_url',    ''),
('yipay_pid',        ''),
('yipay_key',        ''),
('yipay_mode',       'jump'),
('yipay_channels',   'alipay,wxpay'),
('yipay_notify_url', ''),
('yipay_return_url', '');

-- 2. 订单表 pay_channel 兼容说明
--    易支付订单的 pay_channel 取值为 yipay_alipay / yipay_wxpay / yipay_qqpay，
--    列为 varchar 无枚举约束，无需结构变更；此处仅校验列存在（1.0.0 起即有）。
SELECT COUNT(*) INTO @col_exists
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'ly_orders'
   AND COLUMN_NAME = 'pay_channel';
SELECT IF(@col_exists = 1, 'pay_channel 列校验通过', 'pay_channel 列缺失，请检查数据库完整性') AS result;

-- 完成
