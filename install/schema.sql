-- ============================================================
--  LY云计算 - 数据库结构
--  兼容 MySQL 5.7 / 8.0  (utf8mb4, InnoDB)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 用户表
-- ----------------------------
DROP TABLE IF EXISTS `ly_users`;
CREATE TABLE `ly_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(120) NOT NULL COMMENT '邮箱(登录账号)',
  `password` VARCHAR(255) NOT NULL COMMENT '密码哈希',
  `nickname` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '昵称',
  `balance` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额',
  `status` TINYINT NOT NULL DEFAULT '1' COMMENT '1正常 0禁用',
  `email_verified` TINYINT NOT NULL DEFAULT '0' COMMENT '邮箱是否已验证 1是 0否',
  `email_verified_at` DATETIME NULL DEFAULT NULL COMMENT '邮箱验证时间',
  `last_login_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户';

-- ----------------------------
-- 管理员表
-- ----------------------------
DROP TABLE IF EXISTS `ly_admins`;
CREATE TABLE `ly_admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `real_name` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '姓名',
  `status` TINYINT NOT NULL DEFAULT '1',
  `last_login_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员';

-- ----------------------------
-- 商品分类表
-- ----------------------------
DROP TABLE IF EXISTS `ly_categories`;
CREATE TABLE `ly_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(60) NOT NULL COMMENT '分类名称',
  `slug` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '英文标识(用于URL/锚点,小写字母数字短横线)',
  `icon` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '图标名(内置SVG图标键,空=无图标)',
  `color` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '强调色 HEX(空=跟随主题粉)',
  `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '分类描述(前台分类入口副标题)',
  `sort` INT NOT NULL DEFAULT '0' COMMENT '排序,越大越靠前',
  `status` TINYINT NOT NULL DEFAULT '1' COMMENT '1启用 0隐藏',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类';

-- ----------------------------
-- 商品表
-- ----------------------------
DROP TABLE IF EXISTS `ly_products`;
CREATE TABLE `ly_products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL COMMENT '商品名称',
  `subtitle` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '副标题',
  `description` TEXT COMMENT '商品详情(支持换行)',
  `price` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '售价',
  `original_price` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '划线价',
  `cover` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '封面图',
  `tags` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '标签,逗号分隔',
  `spec` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '规格描述,如 1核1G/10M带宽',
  `category_id` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '所属分类ID,0=未分类',
  `stock_mode` TINYINT NOT NULL DEFAULT '1' COMMENT '1使用库存池 0无限库存',
  `auto_deliver` TINYINT NOT NULL DEFAULT '1' COMMENT '1自动发货 0手动发货',
  `deliver_mode` TINYINT NOT NULL DEFAULT '1' COMMENT '交付方式 1库存池卡密 2MNBT实时开通 3人工发货',
  `mnbt_prefix` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'MNBT主机账号前缀(订单号后缀自动拼接)',
  `mnbt_webdx` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT 'MNBT网页空间MB',
  `mnbt_sqldx` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT 'MNBT数据库空间MB',
  `mnbt_sizemax` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT 'MNBT月流量GB',
  `mnbt_type` TINYINT NOT NULL DEFAULT '2' COMMENT 'MNBT产品类型 1=CDN 2=主机',
  `mnbt_ymbds` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT 'MNBT最多绑定域名数',
  `duration_value` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '有效期数值,0=永久有效',
  `duration_unit` ENUM('day','week','month','year') NOT NULL DEFAULT 'month' COMMENT '有效期单位',
  `limit_per_user` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '每人限购次数,0=不限购(按已付款订单计)',
  `sort` INT NOT NULL DEFAULT '0' COMMENT '排序,越大越靠前',
  `sales` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '销量(虚标基数)',
  `status` TINYINT NOT NULL DEFAULT '1' COMMENT '1上架 0下架',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`sort`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品';

-- ----------------------------
-- 库存表(宝塔面板信息)
-- ----------------------------
DROP TABLE IF EXISTS `ly_stocks`;
CREATE TABLE `ly_stocks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `panel_url` VARCHAR(255) NOT NULL COMMENT '宝塔面板登录链接',
  `panel_user` VARCHAR(120) NOT NULL COMMENT '面板账号',
  `panel_pass` VARCHAR(120) NOT NULL COMMENT '面板密码',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注,如到期时间/IP',
  `source` TINYINT NOT NULL DEFAULT '1' COMMENT '来源 1预录导入 2MNBT实时开通',
  `mn_username` VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'MNBT主机账号(source=2时使用)',
  `status` TINYINT NOT NULL DEFAULT '0' COMMENT '0未售 1已售',
  `order_id` INT UNSIGNED NOT NULL DEFAULT '0',
  `sold_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_status` (`product_id`,`status`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存-面板信息';

-- ----------------------------
-- 订单表
-- ----------------------------
DROP TABLE IF EXISTS `ly_orders`;
CREATE TABLE `ly_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL COMMENT '商户订单号',
  `user_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `product_name` VARCHAR(150) NOT NULL DEFAULT '' COMMENT '下单时商品名快照',
  `stock_id` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '已分配库存ID',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '订单总额 = coupon_discount + balance_paid + pay_amount',
  `pay_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '实际需向支付宝支付的金额',
  `balance_paid` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额抵扣金额',
  `coupon_id` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT '使用的优惠券模板ID，0=未用券',
  `coupon_discount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '优惠券减免金额',
  `quantity` INT UNSIGNED NOT NULL DEFAULT '1',
  `pay_channel` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'qr当面付 page电脑网站 balance纯余额 donate捐赠扫码',
  `trade_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '支付宝交易号',
  `user_claimed` TINYINT NOT NULL DEFAULT '0' COMMENT '捐赠支付：用户已声明付款 0/1（1.7.9）',
  `claim_at` DATETIME NULL DEFAULT NULL COMMENT '捐赠支付：声明付款时间（1.7.9）',
  `status` TINYINT NOT NULL DEFAULT '0' COMMENT '0待支付 1已支付 2已发货 3已关闭 4已退款',
  `paid_at` DATETIME NULL DEFAULT NULL,
  `delivered_at` DATETIME NULL DEFAULT NULL,
  `deliver_status` TINYINT NOT NULL DEFAULT '0' COMMENT '自动开通状态 0无需/未开始 1开通中 2已开通 3待重试 4开通失败',
  `deliver_tries` TINYINT UNSIGNED NOT NULL DEFAULT '0' COMMENT '自动开通尝试次数',
  `deliver_error` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近一次开通失败原因',
  `mnbt_username` VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'MNBT主机账号快照',
  `expire_at` DATETIME NULL DEFAULT NULL COMMENT '到期时间(支付时间+有效期),NULL=永久',
  `reminded_at` DATETIME NULL DEFAULT NULL COMMENT '到期提醒邮件发送时间,NULL=未提醒',
  `disposed_at` DATETIME NULL DEFAULT NULL COMMENT '管理员标记已删机时间',
  `client_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_no` (`order_no`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_product_status` (`user_id`,`product_id`,`status`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_expire` (`expire_at`),
  KEY `idx_trade_no` (`trade_no`),
  KEY `idx_coupon` (`coupon_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单';

-- ----------------------------
-- 系统配置表
-- ----------------------------
DROP TABLE IF EXISTS `ly_settings`;
CREATE TABLE `ly_settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统配置';

-- ----------------------------
-- 日志表
-- ----------------------------
DROP TABLE IF EXISTS `ly_logs`;
CREATE TABLE `ly_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(40) NOT NULL DEFAULT '' COMMENT '类型',
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `extra` TEXT,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type_created` (`type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统日志';

-- ----------------------------
-- 邮箱验证码表（注册 / 找回密码）
-- 同邮箱同用途只保留一条，重复发送为覆盖式更新
-- ----------------------------
DROP TABLE IF EXISTS `ly_email_codes`;
CREATE TABLE `ly_email_codes` (
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

-- ----------------------------
-- 找回密码令牌表（库中存 sha256，明文仅出现在邮件链接里）
-- ----------------------------
DROP TABLE IF EXISTS `ly_password_resets`;
CREATE TABLE `ly_password_resets` (
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

-- ----------------------------
-- 兑换码表（库中只存 sha256，明文仅在生成时展示一次）
-- ----------------------------
DROP TABLE IF EXISTS `ly_redeem_codes`;
CREATE TABLE `ly_redeem_codes` (
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

-- ----------------------------
-- 余额流水表（审计用，任何余额变动都必须落一条）
-- ----------------------------
DROP TABLE IF EXISTS `ly_balance_logs`;
CREATE TABLE `ly_balance_logs` (
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

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- 优惠券模板表（后台创建，定义优惠规则）
-- ----------------------------
DROP TABLE IF EXISTS `ly_coupons`;
CREATE TABLE `ly_coupons` (
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

-- ----------------------------
-- 优惠券适用商品表（仅 scope=1 指定商品时使用）
-- ----------------------------
DROP TABLE IF EXISTS `ly_coupon_scopes`;
CREATE TABLE `ly_coupon_scopes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_coupon_product` (`coupon_id`,`product_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优惠券适用商品';

-- ----------------------------
-- 用户优惠券表（券包实例：发放/领取后每人一条，可多次持券）
-- ----------------------------
DROP TABLE IF EXISTS `ly_user_coupons`;
CREATE TABLE `ly_user_coupons` (
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

-- ----------------------------
-- 默认配置
-- ----------------------------
INSERT INTO `ly_settings` (`k`,`v`) VALUES
('site_name',        'LY云计算'),
('site_subtitle',    'IPv6 宝塔面板主机 · 即买即用'),
('site_keywords',    'LY云计算,IPv6主机,宝塔面板,主机购买,云服务器'),
('site_description', 'LY云计算 - 专业提供 IPv6 宝塔面板主机，支付后自动发货，秒级开通面板登录信息。'),
('site_icp',         ''),
('site_contact_qq',  ''),
('site_contact_tg',  ''),
('site_notice',      '本站商品均为 IPv6 宝塔面板主机，下单支付成功后系统自动分配面板登录信息。'),
('site_announce',    '新站上线，全场特惠，支付后自动发货！'),
('order_expire_min', '5'),
('alipay_mode',      'qr'),
('alipay_app_id',    ''),
('alipay_private_key',''),
('alipay_public_key',''),
('alipay_gateway',   'sandbox'),
('alipay_notify_url', ''),
('alipay_return_url', ''),
('demo_pay_enabled', '1'),
('smtp_enabled',          '0'),
('smtp_host',             ''),
('smtp_port',             '465'),
('smtp_encryption',       'ssl'),
('smtp_username',         ''),
('smtp_password',         ''),
('smtp_from_email',       ''),
('smtp_from_name',        'LY云计算'),
('smtp_timeout',          '15'),
('register_email_domains', 'qq.com,foxmail.com'),
('register_email_verify', '1'),
('balance_enabled',       '1'),
('redeem_enabled',        '1'),
('redeem_min_amount',     '0.01'),
('redeem_max_amount',     '99999.00'),
('coupon_enabled',        '1'),
('coupon_claim_enabled',  '1'),
('yipay_switch',          '0'),
('yipay_api_url',         ''),
('yipay_pid',             ''),
('yipay_key',             ''),
('yipay_mode',            'jump'),
('yipay_channels',        'alipay,wxpay'),
('yipay_notify_url',      ''),
('yipay_return_url',      ''),
('donate_enabled',        '0'),
('donate_desc',           '扫描下方收款码完成付款，付款后点击「我已完成付款」，管理员核实到账后即为您发货。'),
('donate_qr_ali_path',    ''),
('donate_qr_wechat_path', '');
