-- ============================================================
--  LY云计算 - 演示数据（可选导入）
--  含 4 个商品 + 6 条面板库存演示数据
--  导入前请先执行 schema.sql
-- ============================================================

-- 管理员：admin / admin888
-- ⚠️ 登录后请立即修改密码！
-- 下方为 admin888 的真实 bcrypt(cost=10) 哈希，可直接用于登录
INSERT INTO `ly_admins` (`username`,`password`,`real_name`,`status`) VALUES
('admin', '$2y$10$sGtd62eDOdp3vzmNb4PEAOrvgXzdn3pDjmFLIW5y5vxAGQV.XVRq6', '超级管理员', 1)
ON DUPLICATE KEY UPDATE `password`=VALUES(`password`);

-- 商品
INSERT INTO `ly_products`
(`name`,`subtitle`,`description`,`price`,`original_price`,`tags`,`spec`,`stock_mode`,`auto_deliver`,`sort`,`sales`,`status`) VALUES
('IPv6宝塔面板主机 · 入门版','1核1G / 10M带宽 / 独立IPv6',
 'CPU：1 核\n内存：1 GB\n硬盘：20 GB SSD\n带宽：10 Mbps\nIPv6：独立原生地址\n面板：宝塔 Linux 面板（预装）\n系统：CentOS 7 / Ubuntu 22.04 可选',
 9.90, 29.90, '热销,IPv6,秒开', '1核 1G / 20G SSD / 10M带宽', 1, 1, 100, 1286, 1),

('IPv6宝塔面板主机 · 标准版','2核2G / 30M带宽 / 独立IPv6',
 'CPU：2 核\n内存：2 GB\n硬盘：40 GB SSD\n带宽：30 Mbps\nIPv6：独立原生地址\n面板：宝塔 Linux 面板（预装）\n系统：CentOS 7 / Ubuntu 22.04 可选',
 19.90, 49.90, '推荐,IPv6', '2核 2G / 40G SSD / 30M带宽', 1, 1, 90, 842, 1),

('IPv6宝塔面板主机 · 高配版','4核4G / 100M带宽 / 独立IPv6',
 'CPU：4 核\n内存：4 GB\n硬盘：80 GB SSD\n带宽：100 Mbps\nIPv6：独立原生地址\n面板：宝塔 Linux 面板（预装）\n系统：CentOS 7 / Ubuntu 22.04 可选',
 39.90, 99.90, '旗舰,IPv6,大带宽', '4核 4G / 80G SSD / 100M带宽', 1, 1, 80, 315, 1),

('IPv6宝塔面板主机 · 不限量版','不限量供应 / 演示无限库存模式',
 'CPU：1 核\n内存：1 GB\n包含宝塔面板登录信息\n库存不限量，支付后立即分配',
 1.00, 0.00, '测试', '1核 1G / 不限量', 0, 1, 10, 56, 1);

-- 面板库存（演示用）
INSERT INTO `ly_stocks` (`product_id`,`panel_url`,`panel_user`,`panel_pass`,`remark`,`status`) VALUES
(1,'http://[2408:8214:1a2b:0c3d::10]:8888/a1b2c3','ly_root_a1','Bt@K9mPq2xL','到期 2027-01-15 / 香港节点',0),
(1,'http://[2408:8214:1a2b:0c3d::11]:8888/d4e5f6','ly_root_a2','Bt@T7vRn4zW','到期 2027-01-15 / 香港节点',0),
(1,'http://[2408:8214:1a2b:0c3d::12]:8888/g7h8i9','ly_root_a3','Bt@H2sJd8fQ','到期 2027-01-15 / 东京节点',0),
(2,'http://[2408:8214:5e6f:1a2b::20]:8888/j1k2l3','ly_root_b1','Bt@P4wXc6vB','到期 2027-02-20 / 新加坡节点',0),
(2,'http://[2408:8214:5e6f:1a2b::21]:8888/m4n5o6','ly_root_b2','Bt@N9yZt3eM','到期 2027-02-20 / 新加坡节点',0),
(3,'http://[2408:8214:9a8b:7c6d::30]:8888/p7q8r9','ly_root_c1','Bt@F5gHj1kV','到期 2027-03-10 / 洛杉矶节点',0);
