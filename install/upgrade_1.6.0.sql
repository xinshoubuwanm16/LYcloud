-- 1.6.0 升级脚本：订单超时自动关闭
--
-- 1) 默认超时从 30 分钟改为 5 分钟（用户需求：5 分钟未支付自动关闭）
--    仅调整仍为默认值 30 的站点；管理员已自定义的其他值保持不变
--    （如需修改请到 后台 → 系统设置 → 订单超时（分钟））
UPDATE ly_settings SET v='5' WHERE k='order_expire_min' AND v='30';

-- 2) 说明：无需新建数据表或字段。
--    超时扫描使用 ly_orders 现有索引 idx_status_created (status, created_at)。
--    可选：在宝塔「计划任务」添加每分钟 Shell 脚本（URL 见后台系统设置）：
--      curl -s "https://你的域名/index.php?r=cron/tick&key=后台显示的密钥"
