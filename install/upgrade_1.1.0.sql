-- ============================================================
--  LY云计算 v1.0.0 → v1.1.0 增量升级脚本
--  新增：SMTP 邮件配置、注册邮箱白名单、邮箱验证码、找回密码
--
--  本脚本是幂等的，可重复执行，不会丢数据。
--  执行方式：
--      mysql -ulycloud -p lycloud < install/upgrade_1.1.0.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) ly_users 增加邮箱验证字段（兼容 MySQL 5.7，无 IF NOT EXISTS 语法）
-- ------------------------------------------------------------
SET @db := DATABASE();

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_users'
                   AND COLUMN_NAME  = 'email_verified');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_users` ADD COLUMN `email_verified` TINYINT NOT NULL DEFAULT 0 COMMENT ''邮箱是否已验证 1是 0否'' AFTER `status`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = @db
                   AND TABLE_NAME   = 'ly_users'
                   AND COLUMN_NAME  = 'email_verified_at');
SET @sql := IF(@exists = 0,
  'ALTER TABLE `ly_users` ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL COMMENT ''邮箱验证时间'' AFTER `email_verified`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) 邮箱验证码表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ly_email_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(120) NOT NULL COMMENT '目标邮箱',
  `purpose` VARCHAR(20) NOT NULL DEFAULT 'register' COMMENT '用途 register/reset',
  `code_hash` CHAR(64) NOT NULL COMMENT '验证码 sha256（不存明文）',
  `attempts` TINYINT NOT NULL DEFAULT '0' COMMENT '已尝试次数',
  `ip` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '请求 IP',
  `expires_at` DATETIME NOT NULL COMMENT '过期时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email_purpose` (`email`,`purpose`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证码';

-- ------------------------------------------------------------
-- 3) 找回密码令牌表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ly_password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户 ID',
  `email` VARCHAR(120) NOT NULL COMMENT '用户邮箱',
  `token_hash` CHAR(64) NOT NULL COMMENT '令牌 sha256',
  `ip` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '请求 IP',
  `expires_at` DATETIME NOT NULL COMMENT '过期时间',
  `used_at` DATETIME NULL DEFAULT NULL COMMENT '使用时间（非空即已失效）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='找回密码令牌';

-- ------------------------------------------------------------
-- 4) 默认配置（INSERT IGNORE：已存在的键不会被覆盖）
-- ------------------------------------------------------------
INSERT IGNORE INTO `ly_settings` (`k`,`v`) VALUES
('smtp_enabled',           '0'),
('smtp_host',              ''),
('smtp_port',              '465'),
('smtp_encryption',        'ssl'),
('smtp_username',          ''),
('smtp_password',          ''),
('smtp_from_email',        ''),
('smtp_from_name',         'LY云计算'),
('smtp_timeout',           '15'),
('register_email_domains', 'qq.com,foxmail.com'),
('register_email_verify',  '1');

SELECT '升级完成：ly_email_codes / ly_password_resets 已就绪，配置项已写入' AS result;
