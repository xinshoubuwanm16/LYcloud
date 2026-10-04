# LY云计算 · IPv6 宝塔面板主机购买系统

一套 **零框架** 的 B2C 虚拟商品自动销售系统。管理员录入商品与面板卡密，用户下单支付后**自动分配**一条「宝塔面板登录链接 + 账号 + 密码」。

- 技术栈：**原生 PHP 8.2 + MySQL 5.7 + Nginx**（无 Composer、无框架、无第三方 SDK）
- 支付通道：**支付宝官方接口**（当面付扫码 + 电脑网站支付跳转）+ **易支付聚合通道**（1.4.0 新增，支持支付宝/微信/QQ 钱包子通道、页面跳转与 API 取码两种模式），两者独立配置、可同时启用，用户下单自选
- 移动端体验（1.5.0 优化）：响应式导航抽屉 + 遮罩动画、头部按钮收纳、触控目标 ≥42px、输入框 16px 防 iOS 聚焦缩放、二维码自适应与手机付款指引、全面屏安全区适配、后台页签横滑与表格横滑
- 邮件可靠性（1.5.1）：SMTP TLS 证书验证失败自动降级宽松验证重试（适配宝塔缺 CA 证书包环境）、错误信息含真实根因与 `openssl.cafile` 配置指引
- 图形验证码（1.5.3）：GD 实现 4 位图形码防刷邮件，一次性 + 哈希存储 + 点击刷新，后台可开关
- 后台可用性（1.5.1）：修复桌面后台主内容区被挤到首屏外的布局缺陷；手机后台列表卡片化，「编辑/删除」等操作按钮全部可见可点
- 升级零缓存（1.5.2）：静态资源版本参数改为文件修改时间指纹，覆盖代码后浏览器缓存自动失效
- 排障直达（1.5.4）：发信失败在页面直接显示真实 SMTP 原因 + 常见错误自动翻译成人话（535 授权码 / 553 发件人不一致 / 554 反垃圾 / 端口封禁超时等）
- 报文合规修复（1.5.5）：修复 DATA 换行被 `str_replace` 串行替换炸成 `\r\r\n\r\n` 导致 QQ 拒信 `550 From header is missing` 的重大 Bug；新增 raw 字节级线上合规测试
- 界面美化（1.7.1）：全站去 emoji 改用内联 SVG 线性图标（后台菜单改数字编号）、主题重绘、首页 Hero 区改为「交付内容」清单卡、文案全面梳理（自动发货 → 自动交付等）；纯模板与静态资源层改动，无逻辑变更。**配色基调已于 1.7.5 改为樱花粉 + 白，此条仅保留交互与文案层面的成果**
- 订单超时自动关闭（1.6.0）：默认 5 分钟未支付自动关单，释放库存并退回优惠券与余额抵扣款；页面访问惰性触发 + 计划任务兜底，支付回调到达时自动恢复误关订单
- 网站弹窗公告（1.7.0）：后台一句话配置，打开网站弹出居中公告窗口（支持多行）；访客关闭后本次会话不再弹出，**公告内容更新后自动重新弹出**（按内容 hash 记忆），输出全程转义防 XSS
- 商品有效期体系（1.7.0）：商品可配置有效期（**天 / 周 / 月 / 年**，0=永久），支付后自动起算到期时间；**到期前 7 天系统自动邮件提醒买家备份数据**；后台新增「到期管理」页与控制台警示条——已到期机器红标提示删机，删机完成后一键标记归档
- 购买限制次数（1.7.4）：商品可设置**每人限购 N 次**（0=不限购），按**已付款订单**计数（待支付/已关闭/已退款不占名额），下单事务内行锁校验防并发绕过；前台显示剩余可购次数，购满自动禁用下单按钮
- 界面主题（1.7.5）：全站改为**樱花粉 + 白 二次元柔和风**——前后台统一色板、大圆角、粉调柔光阴影、渐变按钮；新增 4 张 AI 专属插画（首页主视觉 / 登录页樱花小精灵 / 空状态 / 后台登录背景）+ 樱花 favicon；保留全部响应式与移动端适配
- **MNBT 主机自动开通（1.7.6）**：商品新增第三种交付方式「**MNBT 实时开通**」——支付成功后系统**实时调用梦奈宝塔主机系统（MNBT）API 开通主机**，自动生成账号密码、写入面板信息并展示一键登录入口，取代/补充既有「预录卡密库存池」模式；配套**失败自动重试 + 重试耗尽自动退款到余额**、后台手动重试、开通异常看板提示。详见 [第 15 章](#15-mnbt-主机自动开通176)
- 插画素材扩充（1.7.7）：新增 5 张竖构图二次元插画，分别落位首页 Hero 主视觉（樱花飘瓣）、商品详情页氛围底纹（女仆）、登录/注册页左侧陪衬（星空兔耳）、首页开通流程区块右侧装饰（花鸟拼贴）、余额总览右侧点缀（抱吉他）；全部经裁剪 + WebP 双份输出，`mask-image` 多向渐隐融入粉白底，桌面/平板/手机三档分别调参避免遮挡文字
- **商品分类体系（1.7.8）**：新增 `ly_categories` 分类表与后台「分类管理」页（增删改 / 相邻排序 / 启停 / 内置 16 个 SVG 图标白名单 / 强调色），商品单选归属分类（`category_id`，0=未分类）；前台「全部商品」页分类 **Tab 筛选**（含空分类空态）、**首页分类快捷入口**（仅展示有上架商品的启用分类）、商品详情页分类标签与面包屑；隐藏分类不展示入口但商品仍可见直达；slug 自动生成（中文回落 `cat-{id}`、重名追加 `-2`）；删除分类默认拒绝误删，强制删除自动把旗下商品置为未分类
- **捐赠支付·人工核验（1.7.9）**：新增第四种支付通道「捐赠扫码」——后台「系统设置 → 捐赠收款码」页签上传**支付宝 / 微信双收款码**（JPG/PNG/WebP ≤2MB、≥50×50 真图校验、随机文件名、旧图自动清理），双码任一就绪即启用；下单页**默认选中**捐赠（通道列表首位），点击弹出双码弹窗（可关闭，Esc 生效）；支付页展示双码、金额与收款说明，买家付款后点「我已完成付款」声明（`user_claimed` 幂等标记，订单保持待支付）；管理员在订单详情「捐赠收款核验」卡一键**确认收款并发货**——复用 markPaid 幂等链路自动分配库存并发通知邮件，交易号 `DONATE-` 前缀与真实网关回调区分；订单列表「已声明付款」徽标提示待核验，未到账可直接关单释放库存
- **0 元商品免费领取（1.7.10）**：商品售价支持 **0 元**（后台校验放宽为拒负数、0=免费领取）——0 元商品前台展示「免费领取」、**隐藏支付方式与优惠券选择区**，下单**免支付直接自动发货**（新增 `free` 支付通道，交易号 `FREE-` 前缀，复用纯余额结算链路但不扣余额、零流水）；**券后恰好 0 元**（如满 10 减 10 叠加 10 元商品）同样自动识别走 free 通道即时发货，券正常核销；0 元订单计入限购次数；支付页对 free 订单防呆跳转；前后台订单通道文案显示「免费 / 免费领取（0 元）」；付费商品与纯余额支付链路回归不受影响
- **双主题·蓝白猫娘（1.7.11）**：新增第二套视觉主题「**蓝白猫娘**」（浅蓝长发猫娘 + 白裙 + 黑白猫元素，淡蓝渐变基调），与默认「樱花粉」并存——前台右上角**一键切换**（🌸 樱花粉 ⇄ 🐱 蓝白猫娘），偏好写入 `localStorage` 并在 `<head>` 预置脚本**防首屏闪烁**；主题通过 `<html data-theme="bluecat">` 覆盖 CSS 变量实现，**色板 / 阴影 / 全站背景光斑 / 6 处插画**全部变量化驱动，配套 6 张同风格猫娘插画（首页主视觉 / 商品页底纹 / 登录页 chibi / 空状态 / 流程区块 / 余额页），WebP 输出；纯前端实现、**无数据库变更**，访客偏好仅作用于前台，后台样式不受影响
- **MNBT 对接易用性修复（1.7.12）**：「测试连接」改为**直接使用表单当前值**（改完配置无需先点「保存配置」再测试，密钥输入框留空则沿用已保存密钥）；`mn_vs` **兼容 v1.82 等两位小数版本**（填 `1.82` 自动归一为 `182`，与官方「15 代表 v1.5」拼接约定一致），后台两处提示文案补全示例；`code=300` 版本不匹配报错**携带当前提交的 `mn_vs` 值与正确填写指引**（后台测试结果与订单 `deliver_error` 均可一眼定位）；「测试中…」按钮即时反馈等待说明（MNBT 站点不可达最长约 25 秒）+ 前端 35 秒兜底超时提示，杜绝「点了没反应」的体感问题
- **MNBT 3xx 重定向诊断（1.7.13）**：修复线上实际案例「开通失败：MNBT 接口返回格式异常：301 Moved Permanently cloudflare」——上游套 Cloudflare 强制 HTTPS 时，管理员填 `http://` 地址会被 301 跳到 https，此前拿到跳转页 HTML 误报「返回格式异常」；现 `MnbtClient` 对 3xx **不跟随并直接给出可行动诊断**（标注 `HTTP 301`、携带重定向目标、检测到 http→https 跳转时明确提示「把接口地址改为 https:// 开头」），后台接口地址两处说明同步补充强制 HTTPS 警示

- 邮件服务：**纯 PHP 自研 SMTP 客户端**，用于注册验证码、发货通知、找回密码（零依赖）
- 注册限制：**仅允许 QQ / Foxmail 邮箱注册**（可在后台调整白名单）
- 余额体系：**兑换码充值 + 下单抵扣**，支持部分抵扣（差额走支付宝）与全额抵扣（直接发货），全程 bcmath 精确计算；兑换码面额下限 **0.01 元起**（1.7.3），可发 0.1 / 0.5 等小额码
- 优惠券体系：**满减券 / 折扣券**双类型，支持「全场通用」与「指定商品多选」、每人限领次数、发放总量封顶、后台定向发放 + 前台领券中心自主领取
- 优惠券删除（1.7.2）：券被用户持有时也能删——安全删除仅在「有未使用实例」时拒绝，确认口令「永久删除」可强制收回未使用券并清理模板，**已使用实例保留**（订单金额已快照，用户券包显示「券已删除」）
- 交付形态：`index.php` 直接位于站点根目录，**不使用 `/public`**

---

## 目录

1. [功能概览](#1-功能概览)
2. [目录结构](#2-目录结构)
3. [环境要求](#3-环境要求)
4. [部署步骤](#4-部署步骤)
   - [4.1 安装 Nginx](#41-安装-nginx)
   - [4.2 安装 PHP 8.2](#42-安装-php-82)
   - [4.3 安装 MySQL 5.7](#43-安装-mysql-57)
   - [4.4 部署代码](#44-部署代码)
   - [4.5 配置 Nginx 站点](#45-配置-nginx-站点)
   - [4.6 运行安装向导](#46-运行安装向导)
5. [支付宝对接配置](#5-支付宝对接配置)
   - [易支付对接配置（1.4.0 新增）](#易支付对接配置140-新增)
6. [邮件服务与注册限制（SMTP）](#6-邮件服务与注册限制smtp)
7. [后台使用说明](#7-后台使用说明)
8. [余额与兑换码](#8-余额与兑换码)
9. [优惠券](#9-优惠券)
10. [支付链路时序](#10-支付链路时序)
11. [安全加固清单](#11-安全加固清单)
12. [常见问题](#12-常见问题)
13. [测试](#13-测试)
14. [版本升级](#14-版本升级)
15. [MNBT 主机自动开通（1.7.6）](#15-mnbt-主机自动开通176)

---

## 1. 功能概览

### 界面预览

| 首页 | 商品详情 |
|---|---|
| ![首页](docs/screenshots/01-首页.png) | ![商品详情](docs/screenshots/02-商品详情.png) |

| 收银台 | 面板信息交付 |
|---|---|
| ![收银台](docs/screenshots/03-收银台.png) | ![面板信息交付](docs/screenshots/04-面板信息交付.png) |

| 后台控制台 | 系统设置（支付宝配置） |
|---|---|
| ![后台控制台](docs/screenshots/06-后台控制台.png) | ![系统设置](docs/screenshots/10-系统设置.png) |

| 邮件设置（SMTP + 注册限制） | 注册页（邮箱白名单提示） |
|---|---|
| ![邮件设置](docs/screenshots/11-邮件设置.png) | ![注册页](docs/screenshots/12-注册页.png) |

| 找回密码（未配置 SMTP 时自动降级） | 库存批量导入 |
|---|---|
| ![找回密码](docs/screenshots/13-找回密码.png) | ![库存批量导入](docs/screenshots/09-库存批量导入.png) |

| 商品管理 | 商品表单 |
|---|---|
| ![商品管理](docs/screenshots/07-商品管理.png) | ![商品表单](docs/screenshots/08-商品表单.png) |

> 配图说明：**注册页**截图中间「邮箱地址」下方即为白名单提示（仅支持 `qq.com`、`foxmail.com`）；因未配置 SMTP，页面**不显示验证码输入框**。**找回密码**页会明确提示「站点尚未配置邮件服务」并禁用提交按钮，避免用户误以为功能故障。
### 前台

| 模块 | 说明 |
|---|---|
| 商品列表 / 详情 | 套餐规格、交付内容、库存进度、已售数量 |
| 用户体系 | 邮箱注册 / 登录 / 登出，`bcrypt` 加密存储；**注册限 QQ/Foxmail 邮箱**；**邮箱验证码校验** |
| 找回密码 | 邮件发送一次性重置链接（30 分钟有效、用完即废） |
| 下单结算 | 选择支付方式（当面付扫码 / 电脑网站支付），自动锁库存；**可选用优惠券 + 余额抵扣** |
| 收银台 | 二维码扫码支付，或跳转支付宝收银台，轮询订单状态 |
| 我的订单 | 订单列表、状态筛选、订单详情（含优惠券抵扣、余额抵扣明细） |
| 我的余额 | 余额总览、累计充值/消费/退还统计、兑换码充值、余额明细流水（分页） |
| 我的优惠券 | 统计总览（可使用/已使用/已失效/累计已省）、领券中心自主领券、券包列表 |
| 交付页 | 面板链接 + 账号 + 密码，一键复制；四步进度条（提交订单 → 完成支付 → 自动发货 → 获取面板） |
| 发货邮件 | 支付成功自动发货后，面板信息同步发送到用户注册邮箱 |

### 后台

| 模块 | 说明 |
|---|---|
| 控制台 | 累计销售额、订单数、可用库存、注册用户；7 日销售额趋势图；订单状态分布；库存预警 |
| 商品管理 | 增删改查、上下架、库存模式（限定库存 / 不限库存） |
| 库存管理 | 单条录入 / 批量导入（支持 `|`、`、`、逗号、Tab 分隔，可直接从 Excel 粘贴）、删除、批量删除 |
| 订单管理 | 列表、筛选、详情、手动发货、关闭订单、导出 CSV |
| 用户管理 | 列表、启用 / 禁用、重置密码、**调整余额（充值 / 扣减，带流水审计）** |
| 🎁 兑换码 | 批量生成（面额 + 有效期 + 备注）、明文一次性展示、列表筛选、单个/整批作废、过期清理、批次汇总 |
| 🎟️ 优惠券 | 建券（满减 / 折扣）、全场或指定商品、每人限次、发放总量、**定向发放**、持券人查询、启停、删除 |
| 系统设置 | 站点信息、支付宝接口配置、密钥对生成、连通性测试 |
| 📧 邮件设置 | SMTP 服务器配置、QQ 邮箱授权码引导、测试发信、注册邮箱域名白名单 |

---

## 2. 目录结构

```
lycloud/
├── index.php                  # 唯一入口（Front Controller，位于根目录）
├── favicon.ico                # 站点图标（樱花钱包，多尺寸 ICO）
├── start.sh                   # 一键启动脚本（本地/沙箱环境）
├── nginx.conf.example         # 生产环境 Nginx vhost 模板
├── README.md
│
├── config/
│   └── config.php             # 数据库 / 站点配置（安装向导自动生成，勿提交仓库）
│
├── app/
│   ├── bootstrap.php          # 自动加载、配置加载、会话初始化
│   ├── Router.php             # 路由白名单调度
│   ├── Database.php           # PDO 封装（预处理 + 事务）
│   ├── Auth.php               # 用户/管理员认证、CSRF、登录限流
│   ├── helpers.php            # 全局助手函数
│   │
│   ├── Models/                # 数据模型层
│   │   ├── User.php
│   │   ├── Admin.php
│   │   ├── Product.php
│   │   ├── Stock.php          # 库存分配（行锁防超卖）
│   │   ├── Order.php          # 订单状态机 + 幂等发货 + 金额恒等式自检
│   │   ├── EmailCode.php      # 邮箱验证码（只存 hash、冷却、尝试上限）
│   │   ├── PasswordReset.php  # 找回密码令牌（一次性、批量作废）
│   │   ├── RedeemCode.php     # 兑换码（哈希存储、行锁防并发抢兑）
│   │   ├── BalanceLog.php     # 余额增减 + 流水审计（bcmath 全程）
│   │   ├── Coupon.php         # 优惠券模板（校验 / 试算 / 范围 / CRUD）
│   │   ├── UserCoupon.php     # 用户券实例（发放 / 领取 / 核销 / 退回）
│   │   ├── Category.php       # 商品分类（slug 唯一、图标/颜色白名单、删除保护，1.7.8）
│   │   └── Setting.php        # 站点/支付/邮件配置 KV 缓存 + 邮箱域名白名单
│   │
│   ├── Mail/                  # 邮件服务（纯 PHP，零依赖）
│   │   ├── Mailer.php         # SMTP 客户端（SSL/STARTTLS/AUTH LOGIN、RFC2047 编码）
│   │   └── MailService.php    # 邮件模板层（验证码 / 发货通知 / 找回密码 / 测试）
│   │
│   ├── Payment/               # 支付宝协议实现（纯 PHP）
│   │   ├── AlipaySign.php     # RSA2 签名 / 验签 / 密钥对生成
│   │   └── AlipayGateway.php  # 网关调用 + 回调验签
│   │
│   ├── Mnbt/                  # MNBT 主机系统对接（1.7.6，纯 PHP 零依赖）
│   │   └── MnbtClient.php     # 开通/续费/删除/暂停/重置密码 + 一键登录直链
│   │
│   ├── Controllers/           # 控制器层
│   │   ├── BaseController.php
│   │   ├── HomeController.php
│   │   ├── AuthController.php
│   │   ├── OrderController.php
│   │   ├── PayController.php
│   │   └── admin/             # 后台控制器
│   │
│   └── Views/                 # 视图层（原生 PHP 模板）
│       ├── layout/
│       ├── home/
│       ├── auth/              # 登录 / 注册 / 忘记密码 / 重置密码
│       ├── user/              # 余额 / 我的优惠券
│       ├── order/
│       ├── errors/
│       └── admin/
│
├── assets/
│   ├── css/app.css            # 前台样式（樱花粉 + 白 二次元主题）
│   ├── css/admin.css          # 后台样式（同色板）
│   ├── js/app.js              # 前台交互（复制、轮询、验证码倒计时）
│   ├── js/admin.js            # 后台交互
│   ├── js/qrcode.min.js       # 离线二维码生成器（纯 JS，无外部依赖）
│   └── img/                   # 主题插画（1.7.5 起，1.7.7 扩充）
│       ├── hero-sakura.jpg/.webp  # 首页 Hero 主视觉（1.7.7，竖构图·樱花飘瓣）
│       ├── auth-chibi.png/.webp   # 登录/注册页装饰（樱花小精灵）
│       ├── auth-cosmos.jpg/.webp  # 登录/注册页左侧陪衬（1.7.7，星空兔耳）
│       ├── pd-maid.jpg/.webp      # 商品详情页氛围底纹（1.7.7，女仆）
│       ├── bal-guitar.jpg/.webp   # 余额总览右侧点缀（1.7.7，抱吉他）
│       ├── bal-kotori.jpg/.webp   # 开通流程区块右侧装饰（1.7.7，花鸟拼贴）
│       ├── empty-state.png/.webp  # 空状态插画
│       ├── admin-bg.jpg/.webp     # 后台登录页樱花纹理背景
│       ├── logo-mark.png          # Logo 图标（樱花钱包）
│       ├── logo-mark@2x.png       # Logo 图标 @2x（apple-touch-icon）
│       └── favicon.png            # PNG 版图标
│
├── install/
│   ├── index.php              # 三步安装向导
│   ├── schema.sql             # 建表语句 + 默认配置（含邮件/余额/优惠券相关表）
│   ├── upgrade_1.1.0.sql      # 1.0.0 → 1.1.0 幂等增量升级脚本
│   ├── upgrade_1.2.0.sql      # 1.1.0 → 1.2.0 幂等增量升级脚本
│   ├── upgrade_1.3.0.sql      # 1.2.0 → 1.3.0 幂等增量升级脚本
│   ├── upgrade_1.7.3.sql      # 1.7.2 → 1.7.3 幂等增量升级脚本
│   ├── upgrade_1.7.4.sql      # 1.7.3 → 1.7.4 幂等增量升级脚本
│   ├── upgrade_1.7.6.sql      # 1.7.5 → 1.7.6 幂等增量升级脚本（MNBT 对接）
│   ├── upgrade_1.7.8.sql      # 1.7.7 → 1.7.8 幂等增量升级脚本（商品分类）
│   ├── upgrade_1.7.9.sql      # 1.7.8 → 1.7.9 幂等增量升级脚本（捐赠支付）
│   └── demo_data.sql          # 演示数据（可选）
│
├── runtime/                   # 日志与缓存（需可写）
│
├── docs/
│   └── screenshots/           # 界面截图（文档配图）
│
└── tests/
    ├── flow_test.php          # 业务逻辑单元/集成测试（45 项）
    ├── alipay_test.php        # 支付宝签名测试（34 项）
    ├── smtp_test.php          # SMTP 客户端测试（56 项）
    ├── whitelist_test.php     # 注册邮箱白名单测试（41 项）
    ├── email_flow_test.php    # 验证码与找回密码流程测试（70 项）
    ├── balance_test.php       # 余额与兑换码测试（159 项）
    ├── product_limit_test.php # 商品限购测试（60 项）
    ├── coupon_test.php        # 优惠券测试（252 项）
    ├── coupon_delete_test.php # 优惠券删除测试（38 项）
    ├── category_test.php      # 商品分类测试（133 项，1.7.8）
    ├── donate_test.php        # 捐赠支付测试（90 项，1.7.9）
    ├── announcement_test.php  # 公告测试（23 项）
    ├── captcha_test.php       # 图形验证码测试（32 项）
    ├── expire_test.php        # 有效期体系测试（41 项）
    ├── mailer_wire_test.php   # SMTP 报文合规测试（11 项）
    ├── mnbt_test.php          # MNBT 对接测试（233 项）
    ├── order_expire_test.php  # 订单超时自动关闭测试（28 项）
    ├── setting_switch_test.php # 开关互踩回归测试（16 项）
    ├── tls_fallback_test.php  # TLS 降级测试（6 项）
    ├── free_test.php          # 0 元商品测试（51 项，1.7.10）
    ├── theme_test.php         # 双主题测试（54 项，1.7.11）
    └── web_flow_test.sh       # Web 端到端测试（57 项）
```

---

## 3. 环境要求

| 组件 | 版本 | 说明 |
|---|---|---|
| Linux | CentOS 7+ / Ubuntu 20.04+ / Debian 10+ | 需支持 IPv6 |
| Nginx | 1.18+ | 需配置伪静态 |
| PHP | **8.2** | 需扩展：`pdo_mysql`、`openssl`、`mbstring`、`curl`、`json`、`fileinfo` |
| MySQL | **5.7** | 字符集 `utf8mb4`，引擎 `InnoDB` |
| PHP-FPM | 8.2 | 与 Nginx 通信 |

> **为什么必须是 InnoDB**：库存分配依赖 `SELECT ... FOR UPDATE` 行级锁防超卖，MyISAM 不支持行锁。
>
> **为什么必须开 openssl**：支付宝 RSA2 签名算法（SHA256withRSA）由 `openssl_sign` / `openssl_verify` 实现。

---

## 4. 部署步骤

### 4.1 安装 Nginx

**Ubuntu / Debian**

```bash
apt update && apt install -y nginx
```

**CentOS / RHEL**

```bash
yum install -y epel-release
yum install -y nginx
systemctl enable --now nginx
```

---

### 4.2 安装 PHP 8.2

#### Ubuntu 22.04 / 24.04

Ubuntu 官方仓库的 PHP 版本偏低，使用 **sury** 源安装 8.2。

```bash
apt update
apt install -y software-properties-common curl ca-certificates lsb-release apt-transport-https

# 导入 sury 源 GPG 公钥
curl -fsSL https://packages.sury.org/php/apt.gpg \
  | tee /usr/share/keyrings/sury-php.gpg > /dev/null

# 添加源（把 $(lsb_release -sc) 换成你的发行版代号，如 jammy / noble / bookworm）
echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
  | tee /etc/apt/sources.list.d/sury-php.list

apt update
apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring \
               php8.2-curl php8.2-xml php8.2-gd php8.2-bcmath

systemctl enable --now php8.2-fpm
php -v   # 应输出 PHP 8.2.x
```

> **注意**：`openssl` 与 `fileinfo` 扩展已内置在 `php8.2-common` 中，无需单独安装。
>
> 若 `ondrej/php` PPA 无法访问（网络受限），直接用上面的 sury 源即可。

#### CentOS 7 / Rocky / AlmaLinux

```bash
yum install -y epel-release
yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm

yum install -y yum-utils
yum-config-manager --enable remi-php82
yum install -y php82-php-fpm php82-php-cli php82-php-mysqlnd php82-php-mbstring \
               php82-php-curl php82-php-xml php82-php-gd php82-php-bcmath

systemctl enable --now php82-php-fpm
```

---

### 4.3 安装 MySQL 5.7

MySQL 5.7 已 EOL，主流发行版仓库已移除，需手动安装官方二进制包。

```bash
# 1) 下载官方 5.7 二进制包（示例 5.7.38，可按需换版本）
cd /usr/local/src
wget https://repo.huaweicloud.com/mysql/Downloads/MySQL-5.7/mysql-5.7.38-linux-glibc2.12-x86_64.tar.gz
tar -xzf mysql-5.7.38-linux-glibc2.12-x86_64.tar.gz
mv mysql-5.7.38-linux-glibc2.12-x86_64 /usr/local/mysql

# 2) 创建用户与数据目录
groupadd -r mysql 2>/dev/null || true
useradd -r -g mysql -s /sbin/nologin mysql 2>/dev/null || true
mkdir -p /var/lib/mysql57 && chown -R mysql:mysql /var/lib/mysql57

# 3) 依赖兼容（Ubuntu 24.04 需要）
#    libaio 更名为 libaio.so.1t64
ln -sf /usr/lib/x86_64-linux-gnu/libaio.so.1t64 /usr/lib/x86_64-linux-gnu/libaio.so.1
#    libncurses.so.5 已被移除，需手动补装
#    wget http://mirrors.tencent.com/ubuntu/pool/main/n/ncurses/libtinfo5_6.3-2ubuntu0.3_amd64.deb
#    wget http://mirrors.tencent.com/ubuntu/pool/main/n/ncurses/libncurses5_6.3-2ubuntu0.3_amd64.deb
#    dpkg -i libtinfo5_*.deb libncurses5_*.deb

# 4) 初始化数据目录
/usr/local/mysql/bin/mysqld --initialize-insecure \
  --user=mysql --basedir=/usr/local/mysql --datadir=/var/lib/mysql57
```

创建 `/etc/mysql57.cnf`：

```ini
[mysqld]
user            = mysql
basedir         = /usr/local/mysql
datadir         = /var/lib/mysql57
socket          = /var/run/mysqld/mysqld.sock
port            = 3306
sql_mode        = NO_ENGINE_SUBSTITUTION
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci
default-storage-engine = InnoDB
innodb_buffer_pool_size = 256M
max_connections = 200
log-error       = /var/lib/mysql57/mysql-error.log
pid-file        = /var/run/mysqld/mysqld.pid

[client]
socket          = /var/run/mysqld/mysqld.sock
default-character-set = utf8mb4
```

启动并设置 root 密码与业务库：

```bash
mkdir -p /var/run/mysqld && chown mysql:mysql /var/run/mysqld
setsid /usr/local/mysql/bin/mysqld --defaults-file=/etc/mysql57.cnf --user=mysql < /dev/null &

# 设置 root 密码
/usr/local/mysql/bin/mysql --socket=/var/run/mysqld/mysqld.sock -uroot <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED BY '你的Root密码';
FLUSH PRIVILEGES;
SQL

# 创建业务库与账号
/usr/local/mysql/bin/mysql --socket=/var/run/mysqld/mysqld.sock -uroot -p'你的Root密码' <<'SQL'
CREATE DATABASE lycloud DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lycloud'@'127.0.0.1' IDENTIFIED BY 'LyCloud@2026';
GRANT ALL PRIVILEGES ON lycloud.* TO 'lycloud'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

配置为系统服务（`/etc/systemd/system/mysql57.service`）：

```ini
[Unit]
Description=MySQL 5.7 Server
After=network.target

[Service]
Type=forking
User=mysql
Group=mysql
PIDFile=/var/run/mysqld/mysqld.pid
ExecStart=/usr/local/mysql/bin/mysqld --defaults-file=/etc/mysql57.cnf --user=mysql --daemonize
ExecStop=/usr/local/mysql/bin/mysqladmin --socket=/var/run/mysqld/mysqld.sock -uroot -p'你的Root密码' shutdown
Restart=on-failure
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now mysql57
```

> 如果你的服务器已有 MySQL 5.7（如宝塔面板、云厂商镜像），跳过本节，只需建库建用户。

---

### 4.4 部署代码

```bash
# 上传代码到站点目录（示例路径，按需调整）
mkdir -p /www/wwwroot/lycloud
# 将本项目所有文件解压/上传至 /www/wwwroot/lycloud

cd /www/wwwroot/lycloud

# 目录权限：运行用户需可写 runtime 与 config
chown -R www-data:www-data .          # CentOS 下为 nginx:nginx
chmod -R 755 .
chmod -R 775 runtime config
```

> **关于 `index.php` 位置**：按需求，入口文件在**站点根目录**，不放在 `/public`。所有静态资源通过 `/assets/...` 直接访问，业务请求统一由 `index.php` 分发。

---

### 4.5 配置 Nginx 站点

参考项目根目录的 `nginx.conf.example`。下面是最小可用版本：

```nginx
server {
    listen       80;
    listen       [::]:80;                  # IPv6 监听（本系统主打 IPv6 场景）
    server_name  your-domain.com;          # 或纯 IPv6 地址，如 [2408:xxxx::1]

    root   /www/wwwroot/lycloud;
    index  index.php index.html;

    charset utf-8;
    client_max_body_size 8m;

    # ---------- 安全：敏感目录与文件拦截（必须放在 \.php$ 之前！）----------
    location ~ ^/(app|config|runtime|tests)/ { deny all; return 404; }
    location ~ ^/install/.*\.(sql|md)$       { deny all; return 404; }
    location ~ /\.                            { deny all; return 404; }
    location ~* \.(sql|log|ini|bak|conf|md)$  { deny all; return 404; }

    # ---------- 静态资源 ----------
    location ^~ /assets/ {
        expires 7d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    # ---------- 伪静态：交给入口文件 ----------
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # ---------- PHP ----------
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;   # CentOS: /var/run/php-fpm/www.sock
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include        fastcgi_params;

        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_read_timeout 120s;
    }
}
```

> **坑点提醒**：`location ~ ^/(app|config|...)/` 这类正则 location 与 `location ~ \.php$` 是按**出现顺序**匹配的。如果把敏感目录拦截写在 `\.php$` 之后，访问 `/config/config.php` 会命中 PHP 处理器并**返回 200 把数据库密码吐出来**。务必把 deny 规则前置。

启用站点并重载：

```bash
ln -s /etc/nginx/sites-available/lycloud /etc/nginx/sites-enabled/
# 或直接把 conf 放到 /etc/nginx/conf.d/lycloud.conf
nginx -t && nginx -s reload
```

**HTTPS（可选但推荐）**

```bash
# Let's Encrypt（需域名解析）
certbot --nginx -d your-domain.com
```

然后在 Nginx 配置中追加 HTTPS server 块并开启 HSTS，详见 `nginx.conf.example`。

---

### 4.6 运行安装向导

浏览器访问站点根地址：

```
http://your-domain.com/
```

未检测到 `config/config.php` 时，系统会**自动跳转到** `/install/index.php`。

安装向导共三步：

1. **环境检测** — 检查 PHP 版本、必要扩展、目录可写权限
2. **配置数据库** — 填写数据库主机、端口、库名、用户名、密码；可勾选「导入演示数据」
3. **创建管理员** — 设置管理员账号与密码，完成后自动写入 `config/config.php`

安装完成后**务必删除或重命名 `install/` 目录**：

```bash
mv /www/wwwroot/lycloud/install /www/wwwroot/lycloud/install.lock.bak
```

---

## 5. 支付宝对接配置

### 5.1 签约开通

1. 登录 [支付宝开放平台](https://open.alipay.com/)，使用企业支付宝账号
2. 进入 **控制台 → 网页/移动应用 → 创建应用**（选择「网页应用」）
3. 在 **产品中心** 分别签约开通：
   - **当面付**（对应「扫码支付」）
   - **电脑网站支付**（对应「跳转支付」）
4. 进入 **开发设置 → 接口加签方式**，选择 **公钥模式**（推荐）
5. 在项目后台「系统设置 → 支付宝接口」点击 **生成新密钥对**，得到 RSA2 私钥与应用公钥
6. 将**应用公钥**填入支付宝开放平台「开发设置」，支付宝会返回**支付宝公钥**
7. 把支付宝公钥复制回系统设置

### 5.2 系统内配置

后台 → **系统设置 → 支付宝接口配置**，逐项填写：

| 字段 | 说明 |
|---|---|
| **默认支付方式** | 当面付（扫码支付）/ 电脑网站支付，收银台默认选中项 |
| **网关环境** | 沙箱环境（开发测试）/ 生产环境（正式收款） |
| **APPID** | 开放平台应用 APPID，以 `2021` 开头 |
| **应用私钥** | 第 5 步生成的 RSA2 私钥（PKCS8 或 PKCS1 均可，系统自动规范化） |
| **支付宝公钥** | 开放平台返回的支付宝公钥（**不是**应用公钥） |
| **异步通知地址** | `https://your-domain.com/index.php?r=pay/notify`，可留空自动推导 |
| **同步返回地址** | `https://your-domain.com/index.php?r=pay/return`，可留空自动推导 |
| **演示支付** | 生产环境务必**关闭** |

配置完成后点击 **测试接口连通性** 验证。若显示「已配置」，即可正式下单。

### 5.3 两个关键地址说明

- **异步通知（notify_url）**：支付宝服务器**主动推送**支付结果的地址，是自动发货的**唯一可靠触发点**。必须满足：
  - 公网可访问（本地开发环境需要用内网穿透）
  - 返回纯文本 `success` 表示处理成功，否则支付宝会按 4m/10m/10m/1h/2h/6h/15h 重试
  - **不要**做 302 跳转、不要输出 HTML
- **同步返回（return_url）**：用户支付完成后浏览器跳回的地址。**不可信**（用户可能不等待跳转就关页面），系统在此处会主动调用 `alipay.trade.query` 兜底查询一次。

> 本项目 `notify()` 输出的就是纯文本 `success` / `fail`，符合支付宝协议要求。

---

## 易支付对接配置（1.4.0 新增）

系统支持 **标准易支付协议**（彩虹易支付 / 码支付等聚合支付平台），与支付宝官方接口**互相独立、可同时启用**，用户下单时自行选择支付方式。

### 获取商户凭据

1. 在易支付平台注册商户账号，完成实名与结算配置
2. 在商户后台获取 **商户 PID**（纯数字）与 **商户密钥 KEY**
3. 确认平台支持的支付通道（支付宝 / 微信 / QQ 钱包）

### 系统内配置

后台 → **系统设置 → 易支付**，逐项填写：

| 字段 | 说明 |
|---|---|
| **启用易支付** | 总开关，开启后商品页显示易支付方式 |
| **支付通道** | 勾选开放给用户的子通道：支付宝 / 微信支付 / QQ 钱包（可多选） |
| **接口地址** | 平台首页域名（如 `https://pay.example.com`），系统自动请求 `submit.php` / `mapi.php` |
| **商户 PID** | 平台分配的商户 ID |
| **商户密钥 KEY** | 平台分配的通信密钥（保存后不回显，留空表示不修改） |
| **收银台模式** | **页面跳转**（submit.php，兼容性最好）/ **API 取码**（mapi.php，站内展示二维码并轮询） |
| **异步通知地址** | `https://your-domain.com/index.php?r=pay/yinotify`，可留空自动携带 |
| **同步跳转地址** | `https://your-domain.com/index.php?r=pay/yireturn`，可留空自动携带 |

### 协议实现要点

- **签名算法**：标准 MD5 —— 参数按名 ASCII 升序、剔除 `sign`/`sign_type`/空值、`k=v&` 拼接后末尾直接拼接 KEY，`md5` 小写
- **防篡改**：异步通知除验签外，还校验商户 PID 与订单金额（以 `pay_amount` 为基准）；签名比对使用 `hash_equals` 防时序攻击
- **防串单**：易支付回调只处理 `pay_channel` 为 `yipay_*` 的订单，其他通道订单一律拒绝
- **兼容性**：mapi 响应兼容 BOM / XML 包裹 / CDATA；查单兼容 `trade_status=TRADE_SUCCESS` 与 `status=1` 两种字段风格；查单失败自动容错（部分平台不开放 `api.php`）
- **幂等**：重复通知只发货一次；同步返回页同样有查单兜底

### 与余额、优惠券叠加

易支付订单与支付宝订单共享同一套金额链：

```
amount = coupon_discount（券） + balance_paid（余额） + pay_amount（易支付实付）
```

- 下单时先券、后余额，剩余金额提交给易支付
- 部分余额抵扣的订单在易支付通知确认后（`markPaid`）才真正扣减余额
- 纯余额单直接结算，不经过易支付

---

## 6. 邮件服务与注册限制（SMTP）

系统内置 **纯 PHP 自研 SMTP 客户端**（`app/Mail/Mailer.php`），不依赖 Composer、不依赖任何第三方 SDK，直接基于 socket + SSL 实现。用于三个场景：

| 场景 | 说明 |
|---|---|
| **注册验证码** | 注册时必须填写邮箱收到的 6 位验证码，防止垃圾注册 |
| **发货通知** | 支付成功自动发货后，把「面板链接 + 账号 + 密码」发到用户邮箱 |
| **找回密码** | 发送一次性重置链接，30 分钟内有效 |

### 6.1 QQ 邮箱配置（推荐）

QQ 邮箱 **不能用登录密码** 发信，必须使用「授权码」：

1. 登录 [QQ 邮箱网页版](https://mail.qq.com/) → 顶部 **设置** → **账号**
2. 找到 **POP3/IMAP/SMTP/Exchange/CardDAV/CalDAV服务**
3. 开启 **IMAP/SMTP服务**（需手机发送验证短信）
4. 弹窗中会给你一串 **16 位授权码**，复制保存（只显示一次）
5. 后台 → **系统设置 → 📧 邮件设置**，按下表填写：

| 字段 | 值 |
|---|---|
| 开启邮件服务 | ✅ 勾选 |
| SMTP 服务器 | `smtp.qq.com` |
| 端口 | `465` |
| 加密方式 | `SSL`（465 端口必须选 SSL；若用 587 选 STARTTLS） |
| SMTP 账号 | 你的完整 QQ 邮箱，如 `123456@qq.com` |
| SMTP 密码/授权码 | 第 4 步拿到的 16 位授权码 |
| 发件人邮箱 | 与 SMTP 账号一致，如 `123456@qq.com` |
| 发件人名称 | 显示给收件人的名字，如 `LY云计算` |

保存后点击 **发送测试邮件**，填入自己的邮箱验证。

> **其他邮箱**：163 用 `smtp.163.com:465(SSL)`；阿里企业邮 `smtp.qiye.aliyun.com:465(SSL)`；Gmail 用 `smtp.gmail.com:587(STARTTLS)` + 应用专用密码。

### 6.2 注册邮箱域名限制

后台 → **系统设置 → 📧 邮件设置 → 注册邮箱限制**：

- 默认值为 `qq.com,foxmail.com`，即**只允许 QQ 邮箱与 Foxmail 邮箱注册**
- 多个域名用**英文逗号**分隔，支持分号与中文逗号，大小写不敏感
- 留空表示**不限制**任何邮箱域名
- 支持子域：填 `qq.com` 时 `vip.qq.com` 也会放行（合法 QQ 邮箱域）

匹配采用**严格后缀比对**，以下绕过尝试会被拦截：

| 输入 | 结果 |
|---|---|
| `a@qq.com` | ✅ 通过 |
| `a@foxmail.com` | ✅ 通过 |
| `a@gmail.com` | ❌ 拒绝（不在白名单） |
| `a@notqq.com` | ❌ 拒绝（前缀粘连，非 `qq.com`） |
| `a@qq.com.evil.com` | ❌ 拒绝（后缀伪装） |
| `a@xqq.com` | ❌ 拒绝（前缀粘连） |

> 实现细节：判定条件是 `$domain === $d || substr($domain, -(strlen($d)+1)) === '.' . $d`。若裸用 `str_ends_with($domain, 'qq.com')`，`notqq.com` 会被误判为合法。

### 6.3 验证码安全设计

| 机制 | 说明 |
|---|---|
| 不存明文 | 库中只存 `sha256(验证码)`，防数据库/备份泄露直接利用 |
| 定时安全比较 | 用 `hash_equals()`，避免比较耗时侧信道 |
| 发送冷却 | 同邮箱 **60 秒** 内不能重复发送 |
| 尝试上限 | 连续输错 **5 次** 直接作废该验证码 |
| 一次性 | 验证成功**立即删除**，防重放 |
| 有效期 | **10 分钟** |
| 用途隔离 | `register` 与 `reset` 各自独立记录，不能串用 |
| IP 限流 | `sendCode` 接口单 IP **10 次/小时** |

### 6.4 找回密码安全设计

| 机制 | 说明 |
|---|---|
| 令牌强度 | `bin2hex(random_bytes(32))` = **64 位 hex**，不可枚举 |
| 不存明文 | 库中只存 `sha256(令牌)`，明文只出现在邮件链接里 |
| 一次性 | 使用后 `used_at` 置位，立即失效 |
| 有效期 | **30 分钟** |
| 改密即作废 | 修改密码后**批量作废**该用户全部未用令牌 |
| 重新申请作废旧链接 | 再次申请时先作废旧令牌 |
| 防账号枚举 | 无论邮箱是否存在都返回**同一句提示**，并对不存在的情况加随机延时弱化时间侧信道 |

### 6.5 降级行为（重要）

邮件服务**未配置时不会阻断注册**：

- 注册页**不会显示验证码输入框**，注册流程与未接入邮件时完全一致
- 强制验证码的条件是 `register_email_verify=1` **且** SMTP 配置完整。这样可避免管理员配错 SMTP 后把自己锁在门外
- 发信失败**绝不抛异常**，`Mailer::send()` 顶层 `try/catch` 返回 `false` 并记录日志，不影响支付、下单等主流程
- 支付宝回调中先 `echo 'success'` + `fastcgi_finish_request()` 提前结束请求，再发发货邮件，避免 SMTP 阻塞导致支付宝 5 秒超时重推

---

## 7. 后台使用说明

访问 `http://your-domain.com/index.php?r=admin/auth/login`，或从页脚「管理入口」进入。

默认管理员在安装向导第三步设置。

### 7.1 录入商品

**后台 → 商品管理 → 新增商品**

| 字段 | 说明 |
|---|---|
| 商品名称 | 如「IPv6宝塔面板主机 · 入门版」 |
| 价格 | 单位元，保留两位小数 |
| 库存模式 | **限定库存**（售完下架）/ **不限库存**（虚拟无限） |
| 有效期 | 数值 + 单位（天 / 周 / 月 / 年），**0 = 永久有效**；支付后起算，到期前 7 天邮件提醒 |
| 每人限购 | **每人限购 N 次**（1.7.4），**0 = 不限购**；按已付款订单计数，达到上限后买家无法再下单 |
| 规格说明 | CPU / 内存 / 硬盘 / 带宽 / IPv6 等，前台以规格表展示 |
| 交付说明 | 描述交付内容，前台显示 |
| 排序 / 状态 | 排序值越小越靠前；可上下架 |

### 7.2 导入库存（卡片信息）

**后台 → 库存管理 → 批量导入**

选择商品，然后把面板信息按行粘贴。每行格式：

```
面板链接|面板账号|面板密码|备注(可选)
```

示例：

```
http://[2408:8214:1234::1]:8888/abcdef|ly_cloud_a1|Pa5sw0rd!|到期 2027-01-01
http://[2408:8214:1234::2]:8888/def456|ly_cloud_a2|Pa5sw0rd!|
```

分隔符支持竖线 `|`、英文逗号 `,`、中文顿号 `、`、制表符（可直接从 Excel 复制粘贴）。

系统会逐行校验，跳过格式不完整的行并给出提示。

> **建议**：每次导入后在库存列表确认数量，控制台「库存预警」会高亮剩余不足 3 条的商品。

### 7.3 订单处理

> 余额相关的兑换码管理与用户调账，见 [第 8 章](#8-余额与兑换码)。

- **自动发货**：支付成功 → 支付宝异步通知 → 校验金额 → 行锁分配库存 → 订单标记「已发货」
- **手动发货**：后台 → 订单管理 → 详情 → 手动发货（适用于补发、换货）
- **关闭订单**：释放已锁定的库存
- **导出**：订单列表支持导出 CSV

---

## 8. 余额与兑换码

本版本新增了完整的**余额体系**：用户可通过兑换码充值，下单时用余额抵扣，余额不足的部分继续走支付宝。

### 8.1 金额链设计

一张订单涉及三个金额字段，三者恒满足：

```
order.amount（订单总额） = order.pay_amount（需支付宝支付） + order.balance_paid（余额抵扣）
```

| 字段 | 含义 |
|---|---|
| `amount` | 商品原价，等于订单总额，用于统计销售额 |
| `pay_amount` | **实际需要走支付宝支付的金额**，支付宝下单与回调金额校验都以它为准 |
| `balance_paid` | 本单抵扣掉的余额 |

> ⚠️ 升级注意：回调金额校验基准已从 `amount` 改为 `pay_amount`。否则任何「用过余额」的订单在收到支付宝回调时都会因金额不一致而失败。

**全程使用 `bcmath` 十进制运算**（`bcadd`/`bcsub`/`bcmul`/`bccomp`），不使用浮点，避免 `0.1 + 0.2 !== 0.3` 类精度问题。

### 8.2 抵扣规则

用户在下单页勾选「使用余额抵扣」后，按下表处理：

| 场景 | 余额 | 订单额 | balance_paid | pay_amount | 结果 |
|---|---|---|---|---|---|
| 不使用余额 | 任意 | 100.00 | 0.00 | 100.00 | 走支付宝 |
| 部分抵扣 | 30.00 | 100.00 | 30.00 | 70.00 | 走支付宝付 70 |
| 余额充足 | 500.00 | 100.00 | 100.00 | 0.00 | **即时发货，不走支付宝** |
| 余额不足 | 5.00 | 100.00 | 5.00 | 95.00 | 走支付宝付 95 |
| 零余额 | 0.00 | 100.00 | 0.00 | 100.00 | 走支付宝 |

**纯余额支付**（`pay_amount = 0`）的订单会在下单事务内直接扣款并标记发货，`pay_channel` 记为 `balance`，`trade_no` 以 `BAL` 前缀标识，无需等待任何外部回调。

**抵扣金额在下单时不预扣**（部分抵扣场景），只有纯余额支付才真正扣款。订单若被关闭，已抵扣的余额会**全额退还**并写入 `refund` 流水。

### 8.3 兑换码设计

**后台 → 兑换码** 可以批量生成。安全上做了如下处理：

| 机制 | 说明 |
|---|---|
| 只存哈希 | 数据库仅保存 `sha256(码)` 与掩码（如 `LY7K****P9Q8`），**不保存明文** |
| 明文一次性 | 明文仅在「生成成功」页面展示一次，刷新即消失（session 取出后立刻销毁） |
| 无歧义字符集 | 32 个字符 `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`，剔除易混的 `0 / O / 1 / I` |
| 加密随机 | 使用 `random_int()` 逐位生成，非 `rand()` |
| 唯一约束 | `code_hash` 有唯一索引，插入冲突自动重试（最多 5 次） |
| 面额与有效期 | 单张面额 + 可选有效期天数（留空 = 永久有效） |
| 状态机 | `0 未使用` → `1 已使用` / `2 已作废`，不可逆 |

码格式为 `LY` + 12 位随机字符 = **14 位**，输入时自动转大写，不区分大小写。

### 8.4 兑换流程（防并发重复兑换）

```
用户提交码
  → 格式校验（不符直接拒绝，不查库、不消耗限流额度）
  → 会话级限流（10 次 / 小时）
  → 事务开始
      → SELECT ... FOR UPDATE   ← 行锁，同一码的并发请求在此串行化
      → 校验状态（已用 / 已作废 / 已过期）
      → UPDATE ... WHERE id=? AND status=0   ← 条件更新双保险
      → 余额入账（余额行同样 FOR UPDATE + 条件更新）
      → 写余额流水
  → 事务提交
```

**实测**：60 个并发进程抢兑同一张码，**恰好 1 次成功、59 次被拒**，余额只增加一次，流水只写一条。

### 8.5 余额流水（审计）

所有余额变动都会写入 `ly_balance_logs`，字段包括变动额 `change`、变动前 `before`、变动后 `after`、类型 `type`、关联单号 `ref_id`、备注 `remark`。

流水类型：

| type | 场景 |
|---|---|
| `redeem` | 兑换码充值 |
| `admin` | 管理员手动调账 |
| `consume` | 下单消费 |
| `refund` | 订单关闭退还 |

流水链满足 `after = before + change`，且相邻记录首尾相接，可用于对账。

> 用户可在前台「我的余额」页自行查看；管理员在后台可调账并写入备注。

### 8.6 后台操作

**生成兑换码**

后台 → 兑换码 → 生成兑换码，填写数量（1 ~ 1000）、单张面额、有效期（留空永久）、备注。生成后跳转到结果页一次性展示明文，可「复制全部」贴到 Excel 保存。

**作废**

- 单个作废：列表中未使用的码可单独作废
- 整批作废：批次汇总表中「作废未用」，一次作废该批所有未使用的码
- 清理过期：一键把已过期且未使用的码标记为作废

> 已使用的码**不可作废**（保证流水可追溯）。

**用户调账**

后台 → 用户管理 → 调整余额，填写金额（正数充值、负数扣减）与备注。

- 扣减时若余额不足，会**整体拒绝**并提示当前余额，不会产生部分变动
- 每次调账都写入 `admin` 类型流水，备注一并记录

### 8.7 相关配置项

| 配置键 | 默认值 | 说明 |
|---|---|---|
| `balance_enabled` | `1` | 余额功能总开关（关闭后不可抵扣，余额仍可见） |
| `redeem_enabled` | `1` | 前台自助兑换开关（关闭后仅后台发码） |
| `redeem_min_amount` | `0.01` | 单张兑换码面额下限（1.7.3 起支持 0.1 / 0.5 等小额码） |
| `redeem_max_amount` | `99999.00` | 单张兑换码面额上限 |

---

## 9. 优惠券

> 1.3.0 新增。优惠券是**「券模板 + 用户券实例」两段式**结构，与一次性兑换码不同：同一张券可被多人领取、每人可持有多张、并可分别使用。

### 9.1 数据表设计

| 表 | 作用 | 关键字段 |
|---|---|---|
| `ly_coupons` | **券模板**（规则定义） | `name` `code` `type` `value` `min_amount` `max_discount` `scope` `per_user_limit` `received_limit` `claimable` `status` `start_at` `expires_at` `received_count` `used_count` |
| `ly_coupon_scopes` | **适用范围**（`scope=1` 时生效） | `coupon_id` `product_id`，`UNIQUE(coupon_id,product_id)` |
| `ly_user_coupons` | **用户券实例**（券包） | `coupon_id` `user_id` `source` `status` `order_id` `expires_at` `used_at` |

拆表的原因：`per_user_limit`（每人限次）需要「按用户计数」，把持有关系放进独立表才能既支持 `COUNT(*)` 判定，又能让 `UNIQUE` 约束和行锁落到具体实例上。

订单侧新增两列：`ly_orders.coupon_id`（使用的券模板 ID）与 `ly_orders.coupon_discount`（券减免金额）。

### 9.2 两种券型

| 类型 | `type` | `value` 含义 | 减免公式 | 校验约束 |
|---|---|---|---|---|
| **满减券** | `reduce` | 减免金额（元） | `min(value, amount)`，且需 `amount >= min_amount` | `value > 0`；`min_amount >= value`（防 0 元购） |
| **折扣券** | `discount` | 折扣率（0.85 = 8.5 折） | `amount × (1 − value)`，再按 `max_discount` 封顶 | `0.01 <= value <= 0.99` |

未达 `min_amount` 门槛时减免为 `0.00`，且**不会出现在结算下拉框里**（服务端过滤，前端不可绕过）。

### 9.3 适用范围

- **全场通用**（`scope = 0`）：对所有商品可用，`ly_coupon_scopes` 无记录。
- **指定商品**（`scope = 1`）：后台建券时多选商品，逐条写入 `ly_coupon_scopes`。下单时服务端查表判定，不匹配直接拒绝。

切换范围时（全场 ⇄ 指定）会自动清理/重写 `ly_coupon_scopes`，不会残留脏数据。

### 9.4 领取方式

两条通道共用同一套「每人限次 + 总量封顶」校验：

1. **后台定向发放**：管理员按用户多选发放（也支持按邮箱批量），单次上限 200 人。
2. **前台领券中心**：用户自主领取，受会话级限流（1 小时内最多 20 次）保护；真正的一致性由事务 + 行锁保证。

发放/领取都会累加券模板的 `received_count`。达到 `received_limit` 后自动从领券中心下架，并发场景下由 `SELECT ... FOR UPDATE` 保证不超发。

### 9.5 抵扣顺序：先券后余额

结算时金额链为**固定顺序**，不可颠倒：

```
订单总额 amount
  ├─ ① 优惠券减免 coupon_discount   ← 先算，以商品原价为基数
  ├─ ② 余额抵扣 balance_paid        ← 以「券后应付」为基数
  └─ ③ 支付宝实付 pay_amount        ← 剩余部分
```

> **恒等式**：`amount = coupon_discount + balance_paid + pay_amount`
> 每次下单都会自检该等式，不成立即抛异常回滚，杜绝金额凭空多出或少掉。

举例：商品 100 元，用「满 100 减 20」券，账户余额 50 元 →

| 项 | 金额 |
|---|---|
| `amount` | 100.00 |
| `coupon_discount` | 20.00 |
| `balance_paid` | 50.00（券后 80，余额只够 50） |
| `pay_amount` | 30.00（剩余走支付宝） |

若余额 ≥ 券后应付（80），则 `pay_amount = 0.00`，订单直接完成并自动发货，不走支付宝。

### 9.6 核销与退回

- **核销**：下单事务内对券实例加行锁，并用**条件 UPDATE**（`status=0 → 1`）核销；影响行数为 0 即说明券已被占用，整个订单回滚。并发下同一张券只有 1 次能成功。
- **退回**：待支付订单关闭时，券自动退回券包（`status=1 → 0`，`order_id` 归零），`used_count` 同步减 1，券可再次使用。
- **删除保护**：券若已有持券人或已被订单使用，拒绝删除（提示改为「停用」），避免破坏历史记录。

### 9.7 后台操作

侧栏 **优惠券** 菜单：

| 页面 | 路由 | 说明 |
|---|---|---|
| 优惠券列表 | `admin/coupon/list` | 支持按名称/券码/状态筛选，展示发放量与使用量 |
| 新建 / 编辑 | `admin/coupon/form` | 选券型、面额/折扣、门槛、上限、范围（可多选商品）、时间窗 |
| 定向发放 | `admin/coupon/grant` | 按用户多选或按邮箱批量发放 |
| 持券人 | `admin/coupon/holders` | 查看某券的持有者、来源（后台/自领）、状态、关联订单 |
| 启停 | `admin/coupon/toggle` | 一键停用/启用（停用后不可领取也不可用于下单） |
| 删除 | `admin/coupon/delete` | 有持券人或已被使用时拒绝 |

### 9.8 前台入口

- **我的优惠券**（导航栏 / 顶部按钮）：`user/coupons` — 上方为**领券中心**（可领券卡片，含剩余量与限领说明），下方为**我的券包**（按可使用 / 已使用 / 已失效分组展示），顶部统计「可使用 / 已使用 / 已失效 / 累计已省」。
- **商品详情页**：登录后自动拉取该商品可用券，渲染为下拉框并实时提示预计减免；也可选择不使用券。
- **订单详情页**：显示「优惠券抵扣」行（含券名），与「余额抵扣」「实付金额」一并呈现。

### 9.9 相关配置项

| 配置键 | 默认值 | 说明 |
|---|---|---|
| `coupon_enabled` | `1` | 优惠券功能总开关（关闭后下单忽略券，券不核销） |
| `coupon_claim_enabled` | `1` | 领券中心开关（关闭后仅保留后台定向发放；依赖 `coupon_enabled`） |

### 9.10 并发与安全要点

| 风险 | 防护 |
|---|---|
| 一券多用 | 下单事务内 `FOR UPDATE` 锁券实例 + 条件 UPDATE 核销 |
| 限量券超发 | 领取事务内 `FOR UPDATE` 锁券模板，`received_count` 为权威值 |
| 越过每人限次 | 服务端 `COUNT(*)` 判定，不信任前端 |
| 使用他人券 | 校验券实例 `user_id` 必须等于当前登录用户 |
| 前端篡改金额 | 试算接口以服务端商品价为准；下单时重新试算并自检恒等式 |
| 0 元购（满减） | 建券时强制 `min_amount >= value` |
| 0 元购（折扣） | 折扣率上限 `0.99`，减免额不超过订单本金 |
| 金额精度漂移 | 全程 `bcmath`（`bcadd`/`bcsub`/`bcmul`/`bccomp`），统一 `SCALE = 2` |

---

## 10. 支付链路时序

```
用户                前台              服务端                  支付宝
 │                   │                  │                       │
 ├─ 选择商品 ────────>│                  │                       │
 ├─ 点击购买 ────────>│─ POST order/create ─>│                    │
 │                   │                  ├─ 行锁分配库存          │
 │                   │                  ├─ 创建订单(status=0)    │
 │                   │<─ 302 收银台 ─────│                       │
 │                   │                  │                       │
 │                   │                  ├─ alipay.trade.precreate ─────>│
 │                   │                  │<──── qr_code ────────────────│
 │<─ 显示二维码 ──────│<─────────────────│                        │
 ├─ 扫码付款 ─────────────────────────────────────────────────────>│
 │                   │                  │                       │
 │                   │                  │<─ POST pay/notify ────────────│
 │                   │                  ├─ 验签                 │
 │                   │                  ├─ 校验 app_id / 金额   │
 │                   │                  ├─ 幂等判断             │
 │                   │                  ├─ 行锁分配库存          │
 │                   │                  ├─ status=2 (已发货)     │
 │                   │                  ├─ 输出 "success" ─────────────>│
 │                   │                  │                       │
 │<─ 轮询 order/queryStatus ───────────>│                       │
 │<─ 显示面板链接/账号/密码 ────────────│                        │
```

**幂等保证**：支付宝在未收到 `success` 前会重复推送同一笔通知。系统通过「订单行锁 + `status !== PENDING` 提前返回」确保**同一订单只发货一次**，重复通知直接返回 `success` 不再分配库存。

**超卖防护**：库存分配使用

```sql
SELECT * FROM ly_stocks
 WHERE product_id = ? AND status = 0
 ORDER BY id ASC LIMIT 1
 FOR UPDATE;                          -- 行级锁，串行化并发下单
```

随后 `UPDATE ly_stocks SET status=1 WHERE id=? AND status=0`，通过 `rowCount() === 0` 判定抢占失败并放弃。双重保险。

---

## 11. 安全加固清单

上线前请逐项确认：

- [ ] **删除 `install/` 目录**（或重命名为不可猜解的名字），防止被重装
- [ ] **关闭「演示支付」开关**（后台 → 系统设置），否则任何人可零元购
- [ ] 修改 `config/config.php` 权限为 `640`，属主为 PHP 运行用户
- [ ] 确认 Nginx 敏感目录拦截生效，逐条验证：
  ```bash
  curl -I http://your-domain.com/config/config.php   # 期望 404
  curl -I http://your-domain.com/app/bootstrap.php   # 期望 404
  curl -I http://your-domain.com/runtime/            # 期望 404
  curl -I http://your-domain.com/install/schema.sql  # 期望 404
  curl -I http://your-domain.com/README.md           # 期望 404
  curl -I http://your-domain.com/start.sh            # 期望 404
  curl -I http://your-domain.com/nginx.conf.example  # 期望 404
  ```
  > 已在沙箱环境实测：以上路径全部返回 404，而 `/assets/*.css`、`/assets/*.js`、首页均正常返回 200。
- [ ] 生产环境关闭调试：`config/config.php` 中 `LY_DEBUG` 设为 `false`
- [ ] 配置 HTTPS，并开启 HSTS
- [ ] 修改数据库默认密码，使用强密码
- [ ] 后台管理路径可考虑加 IP 白名单：
  ```nginx
  location ~ ^/index\.php$ {
      # 仅允许可信 IP 访问后台路由（需配合 Nginx map 或 Lua，简单场景用如下方式）
  }
  ```
  更简单的做法是限制 `/index.php` 的 `r=admin` 查询参数来源，或直接用防火墙限制后台入口端口。
- [ ] 配置日志轮转，`runtime/` 目录定期清理
- [ ] 定期备份 `lycloud` 数据库与 `config/config.php`
- [ ] 确认**注册邮箱域名白名单**符合预期（默认只允许 `qq.com` / `foxmail.com`）
- [ ] SMTP 授权码属于敏感凭据：请确认 `config/config.php` 与数据库均不可通过 HTTP 访问
- [ ] 邮件发送使用独立邮箱，避免与个人主邮箱共用（防授权码泄露影响面扩大）

### 已内置的安全机制

| 机制 | 实现位置 |
|---|---|
| SQL 注入防护 | `Database.php` 全部使用 PDO 预处理绑定 |
| XSS 防护 | 视图输出统一走 `e()`（`htmlspecialchars`） |
| CSRF 防护 | `Auth::csrfField()` / `Auth::verifyCsrf()`，`hash_equals` 定时安全比较 |
| 密码存储 | `password_hash(PASSWORD_BCRYPT, cost=10)` |
| 登录限流 | 同邮箱 5 次 / 10 分钟，超限锁定 |
| 会话安全 | `HttpOnly` + `SameSite=Lax` |
| 路由白名单 | `Router.php` 显式白名单，未登记路由直接 404，杜绝任意文件包含 |
| 路径穿越防护 | 路由含 `..` 直接 404 |
| 支付回调验签 | `AlipayGateway::verifyNotify()` RSA2 验签 + `app_id` 校验 + 金额 `bccomp` 比对 |
| 资源访问控制 | 未登录访问订单页 → 302 登录并记录 `_intended` |
| 注册域名白名单 | `Setting::emailDomainAllowed()` 严格后缀比对，拦截 `notqq.com` 类绕过 |
| 验证码存储 | 库中只存 `sha256(验证码)`，`hash_equals` 定时安全比较 |
| 验证码防爆破 | 60s 冷却 + 5 次尝试上限 + 成功即销毁 + IP 限流 10 次/小时 |
| 找回令牌存储 | 库中只存 `sha256(令牌)`，明文仅出现在邮件链接 |
| 找回令牌生命周期 | 30 分钟有效、一次性、改密后批量作废、重复申请作废旧链接 |
| 防账号枚举 | 找回密码对存在/不存在的邮箱返回同一提示，并加随机延时 |
| 发信失败隔离 | `Mailer::send()` 顶层 `try/catch`，发信异常不影响下单/支付主流程 |
| 回调超时保护 | 支付回调先 `echo 'success'` + `fastcgi_finish_request()`，再异步发发货邮件 |

---

## 12. 常见问题

**Q1：访问站点一直跳转到 `/install/index.php`**

说明 `config/config.php` 不存在或不可读。确认文件存在、权限正确（`640`，属主与 PHP-FPM 运行用户一致）。若已安装完成，请删除 `install/` 目录。

**Q2：页面报 500，日志显示 `Class "App\Models\XXX" not found`**

Linux 文件系统**大小写敏感**。确认 `app/` 下目录名与命名空间完全一致：`Models`、`Payment`、`Controllers`、`Views`（首字母大写）。同时确认 `app/bootstrap.php` 中是单一 `namespace App;` 声明，而不是 `namespace {}` 块。

**Q3：支付宝回调收不到 / 一直显示「待支付」**

- 确认 `notify_url` 是**公网可访问**的 HTTPS/HTTP 地址
- 检查服务器防火墙是否放行支付宝服务器 IP
- 确认 `notify()` 返回的是纯文本 `success`（不要有 BOM、不要有 HTML 包裹）
- 在支付宝开放平台「交易查询」中查看通知记录与返回内容
- 若本地开发，用 ngrok / frp 等内网穿透工具

**Q4：扫码后提示「验签失败」**

- 最常见原因：把**应用公钥**填到了「支付宝公钥」字段。这两个不是同一个东西
- 确认开放平台的加签方式是 **公钥模式（RSA2）**
- 确认后台「网关环境」与实际使用的 APPID 匹配（沙箱 APPID 只能配沙箱网关）

**Q5：并发下单会不会超卖？**

不会。库存分配走 `SELECT ... FOR UPDATE` 行级锁，同一商品的并发请求会串行执行；再配合 `UPDATE ... WHERE status=0` 的 `rowCount()` 判定做二次保险。表的存储引擎必须是 **InnoDB**。

**Q6：二维码不显示**

页面二维码由 `assets/js/qrcode.min.js` 在**浏览器端离线生成**，不依赖任何外部接口。若没显示，检查该文件是否被正确部署、是否被 Nginx 拦截规则误伤（`\.js$` 不应被 deny 规则覆盖）。

**Q7：演示支付按钮是干嘛的？**

沙箱/内网环境无法接收支付宝外网回调时，用于验证「下单 → 支付 → 自动发货 → 查看面板」完整链路。**生产环境必须在系统设置中关闭**，否则存在零元购风险。

**Q8：填了 SMTP 但收不到邮件 / 测试发信失败**

按这个顺序排查：

1. **QQ 邮箱必须用「授权码」，不能用登录密码**。授权码在 QQ 邮箱网页版 → 设置 → 账号 → 开启 IMAP/SMTP 服务 后获得（16 位）
2. **端口与加密方式必须匹配**：`465` 配 `SSL`；`587` 配 `STARTTLS`。选错会一直连不上
3. **发件人邮箱要与 SMTP 账号一致**，否则 QQ 会以「发件人与登录账号不符」拒发
4. 点「发送测试邮件」看具体报错。系统只回一句通用提示，**详细原因记在数据库 `ly_logs` 表**（`type = 'smtp_error'`），能看到真实失败原因，例如：
   ```sql
   SELECT created_at, message FROM ly_logs WHERE type='smtp_error' ORDER BY id DESC LIMIT 10;
   ```
   典型返回如 `连接 SMTP 服务器 smtp.qq.com:465 失败：Connection refused`（网络/防火墙问题）、`AUTH LOGIN 失败`（授权码错误）。
5. 收件箱没有就翻 **垃圾箱**，QQ 对新域名发信容易进垃圾箱
6. 服务器防火墙需放行**出站** 465/587 端口（部分云厂商默认封禁 25 端口）

**Q9：为什么注册页没有验证码输入框？**

设计如此 —— 验证码只在「开关打开 **且** SMTP 配置完整」时才强制。请到后台「系统设置 → 📧 邮件设置」确认：① 勾选了「开启邮件服务」；② 服务器 / 账号 / 授权码 / 发件人邮箱四项都填了。这样设计是为了防止管理员 SMTP 配错后把新用户和自己都锁在门外。

**Q10：想允许 163、Gmail 等邮箱注册怎么办？**

后台 → 系统设置 → 📧 邮件设置 → 「注册邮箱限制」里追加域名即可，例如 `qq.com,foxmail.com,163.com,gmail.com`。**留空表示不限制任何邮箱**。

**Q11：升级到 1.1.0 后老用户能登录吗？**

能。升级脚本给 `ly_users` 补的 `email_verified` 默认是 `0`，这个字段目前只做记录，**不参与登录校验**，不会影响存量用户。升级脚本是幂等的，可放心重复执行。

---

## 13. 测试

项目自带 **23 套测试，共 1636 项断言**，全部通过。

```bash
cd /www/wwwroot/lycloud

# 1) 业务逻辑单元/集成测试（45 项）
php tests/flow_test.php

# 2) 支付宝签名与网关测试（34 项）
php tests/alipay_test.php

# 3) SMTP 客户端测试（56 项）
php tests/smtp_test.php

# 4) 注册邮箱白名单测试（41 项）
php tests/whitelist_test.php

# 5) 验证码与找回密码流程测试（70 项）
php tests/email_flow_test.php

# 6) 余额与兑换码测试（159 项）
php tests/balance_test.php

# 7) 优惠券测试（252 项）
php tests/coupon_test.php

# 8) 易支付全链路测试（129 项）
php tests/yipay_test.php

# 9) 设置开关互踩回归测试（16 项，需先启动本地 HTTP 服务）
#    php -S 127.0.0.1:8099 -t . 后再执行
php tests/setting_switch_test.php

# 10) SMTP TLS 证书验证降级测试（6 项，需 proc_open + openssl 命令，缺失自动 SKIP）
php tests/tls_fallback_test.php

# 11) 图形验证码测试（32 项，需先启动本地 HTTP 服务）
php tests/captcha_test.php

# 12) Web 端到端测试（57 项，需站点已运行）
LY_BASE=http://127.0.0.1:8099 bash tests/web_flow_test.sh

# 13) SMTP 报文线上字节级合规测试（11 项，需 proc_open；raw 抓包杜绝 mock 假象）
php tests/mailer_wire_test.php

# 14) 订单超时自动关闭测试（28 项，需先启动本地 HTTP 服务）
LY_TEST_BASE=http://127.0.0.1:8099 php tests/order_expire_test.php

# 15) 网站公告测试（23 项，需先启动本地 HTTP 服务）
LY_TEST_BASE=http://127.0.0.1:8099 php tests/announcement_test.php

# 16) 商品有效期体系测试（41 项，需先启动本地 HTTP 服务）
LY_TEST_BASE=http://127.0.0.1:8099 php tests/expire_test.php

# 17) 优惠券删除测试（38 项，需先启动本地 HTTP 服务）
LY_TEST_BASE=http://127.0.0.1:8099 php tests/coupon_delete_test.php

# 18) 商品购买限制次数测试（60 项，需先启动本地 HTTP 服务）
LY_TEST_BASE=http://127.0.0.1:8099 php tests/product_limit_test.php

# 19) MNBT 主机自动开通测试（210 项，自带 mock MNBT 服务，无需手工起服务）
php tests/mnbt_test.php

# 20) 商品分类测试（133 项，需先启动本地 HTTP 服务）
LY_TEST_BASE=http://127.0.0.1:8099 php tests/category_test.php
```

| 测试 | 项数 | 覆盖范围 |
|---|---|---|
| `flow_test.php` | 45 | 库存行锁分配、并发抢占、无限库存、订单状态机、幂等发货、重复通知 |
| `alipay_test.php` | 34 | RSA2 签名字典序拼接、空值排除、验签、密钥对生成、网关参数 |
| `smtp_test.php` | 56 | 配置读取、RFC 2047 中文主题编码与折行、MIME 报文组装、响应码解析、**发信失败绝不抛异常** |
| `whitelist_test.php` | 41 | 域名解析归一、白名单命中、**`notqq.com`/`qq.com.evil.com`/`xqq.com` 绕过拦截**、子域规则、空名单不限制 |
| `email_flow_test.php` | 70 | 验证码生成（只存 hash）、冷却、成功/失败/尝试上限/过期、用途隔离、令牌签发/校验/一次性/过期/批量作废/清理 |
| `balance_test.php` | 159 | 余额 bcmath 精度、余额不足拒绝扣减、流水 before/after 链、兑换码生成/掩码/哈希存储/格式校验、**小额面额 0.1 / 0.5 / 0.01 可生成且落库精度正确（1.7.3）**、**60 并发抢兑同一码只成功 1 次**、过期与作废、订单部分/全额/不足抵扣、**关单退款区分「已扣款才退」**、库存不足事务回滚、开关控制 |
| `coupon_test.php` | 252 | 券参数校验（含 0 元购防护、折扣率区间、券码 `[A-Z0-9]{4,20}` 归一）、满减/折扣试算（含封顶、门槛边界、分位截断）、适用范围判定、CRUD 与启停、删除保护、发放（每人限次跳过 + 总量封顶 + 用户去重）、自领（可领/时间窗/领完/限次）、**10 人抢 1 张券只成功 1 人**、先券后余额恒等式、**8 次并发用同一张券只成功 1 次**、关单退券、开关关闭时忽略券、全库金额恒等式巡检 |
| `yipay_test.php` | 129 | 标准 MD5 签名（独立实现交叉验证、中文、空值、顺序无关）、pay_channel 工具、宽松 JSON 解析、通知验签（含单入口 `r` 参数回归）、下单白名单、HTTP 端到端（取码→通知→发货→幂等→防伪→串单防护）、券+余额+易支付叠加恒等式、跳转模式、后台保存、关单安全、轮询查单、异常容错 |
| `coupon_delete_test.php` | 38 | **优惠券被持有时删除（1.7.2）**：无人持有时安全删除、有未使用实例时拒绝（含张数与口令文案）、**仅剩已使用实例不阻塞删除**、删模板后券包 `LEFT JOIN` 查询正常且券名回退 NULL（前台「券已删除」）、**强制删除级联**（收回未使用 2 张、已使用 1 张保留、`ly_coupon_scopes` 清理）、券不存在返回失败、HTTP 端到端口令三态（无 force 拒绝 / 口令错误拒绝 / 正确口令删除成功） |
| `product_limit_test.php` | 60 | **商品购买限制次数（1.7.4）**：限购值归一化（负数→0、超 9999 封顶、小数截断）、`limitText`/`canPurchase`/`remainingQuota` 语义、**计数口径**（已支付+已发货计入；待支付/已关闭/已退款不计入）、达到上限后 `createWithStock` 抛拒绝且订单不入库、**限购按商品独立**、不限购商品连下 5 单不受影响（回归）、后台保存与边界（99999→9999、-5→0）、列表「限 N 次」徽章、**HTTP 前台**（访客见规则、购满用户见「已购满 N 次」+ 按钮 disabled、有额度用户见「还可购 N 次」）、**并发：限购额度已满时并发 8 个下单全部被拒且订单数不增（FOR UPDATE 行锁）** |
| `mnbt_test.php` | 233 | **MNBT 主机自动开通（1.7.6；1.7.12/1.7.13 扩展）**：★**自带 mock MNBT 服务**（自动选端口启动/清场/关闭，无需手工起服务）。① `MnbtClient`：配置判定与 `missingFields`、接口地址规范化（补协议 / 裁 `/api/api.php` / 去尾斜杠）、9 个业务接口（`cfif`/`kt`/`xf`/`tz`/`zt`/`jc`/`czmm` + 一键登录）、`code=100` 业务失败 / `code=300` 版本不匹配 / 非 JSON 响应 / 网络不可达 / **超时 5s 内中断**、**`loginUrl` 绝不携带 `mn_key`/`mn_keye`/`mn_bh`/`mn_vs`（密钥不得暴露给终端用户）**。② `Setting`：默认值、边界钳制（`timeout` 5~60、`connect_timeout` 3~30、`max_retry` 0~10）、`mnbtConfigured` 完整性。③ `Product`：交付方式三态常量、`normalizeDeliverMode` 归一（0/99→1）、`isMnbt`、规格文案、真实表读写往返。④ **发货流程**：MNBT 商品**不占用本站库存池**、`markPaid` → **事务提交后**调 MNBT（不在持锁事务内发 HTTP）→ 写 `source=2` 面板记录、`Order::panel()` 复用既有链路可查、**重复回调幂等（不重复开通、不产第二条记录）**、**连续 5 单账号互不重复**、密码 12 位且剔除易混淆字符 `0O1lI`、纯余额路径同样触发开通、`expire_at` 与 MNBT 到期日联动（+3 月日历语义）。⑤ **失败重试与退款兜底**：首次失败→`RETRY` 且余额不动、**额度未耗尽不退款**、**`tries` 达 `max_tries = max_retry + 1` 才置 `FAILED` + 自动退款**、退款幂等（不重复退款）、`max_retry=0` 首次即退、**网络不可达时 `markPaid` 不抛异常**（支付照常成功、订单保持已支付等重试）、故障恢复后自动重试成功并清空失败原因、**人工重试不受次数限制且失败不自动退款**（防排障期资损）、状态门禁（已开通/已退款/不存在订单均拒绝）、`mnbtAttentionCount` 统计口径、**库存池商品全流程回归不受影响**、MNBT 关闭不影响下单。⑥ **详情页与一键登录中转**：前台/后台详情页渲染要素（主机卡片、FTP 账号密码、规格、开通中/失败态文案、状态轮询脚本）、**页面 HTML 不含 MNBT 直链与 API 密钥**、**归属校验（越权拦截，管理员可跨用户）**、状态门禁（开通中/待重试/失败均不可登录）、后台「手动重试开通」「代用户登录面板」按钮、后台列表「开通状态」列与重试入口。⑦ **测试连接用表单当前值（1.7.12）**：`mergeMnbtFormConfig` 合并规则（表单值优先 / 密钥留空回落已保存值 / `api_url` 裁剪 / 未提交字段沿用）、**`mn_vs` 兼容小数写法**（`1.82`/`v1.82` → `182`、无数字回落已保存值）、超时字段钳制；端到端——已保存 `vs=17` 必被上游拒绝时**表单 `vs=16` 不保存直接测试成功**（表单值优先生效）、`vs=1.82 → 182` 触发 `code=300` 且**报错携带 `mn_vs=182` 与「v1.82 填 182」填写指引**。⑧ **HTTP 3xx 重定向诊断（1.7.13）**：mock 上游返回 301（模拟 Cloudflare 强制 HTTPS）→ **不跟随、不再误报「返回格式异常」**，报错标注 `HTTP 301`、携带重定向目标 https 地址、给出「把接口地址改为 https:// 开头」的可行动指引 |
| `setting_switch_test.php` | 16 | **设置开关互踩回归（1.5.0）**：SMTP 表单与注册限制表单相互独立保存，互不清除对方开关；缺省补 0、密码留空保护、域名白名单 |
| `tls_fallback_test.php` | 6 | **TLS 证书验证降级（1.5.1）**：自签名证书 mock SMTP（模拟服务器缺 CA 包）→ 严格模式原始错误含证书细节、`send()` 自动降级宽松验证并投递成功、降级后错误清空、错误识别函数不误判 AUTH 失败（需 proc_open + openssl，缺失自动 SKIP） |
| `order_expire_test.php` | 28 | **订单超时自动关闭（1.6.0）**：cron 端点密钥校验（缺失/错误 403、正确执行、cron_key 惰性生成）、惰性触发关单、**关单释放库存 + 退回优惠券 + 退回纯余额抵扣**、未超时/已支付订单不受影响、**markPaid 恢复兜底（超时关闭后回调到达 → 重新分配库存发货；无库存 → 实付金额退到余额并标记已退款）**、重复回调幂等、minutes=0 开关 |
| `announcement_test.php` | 23 | **网站弹窗公告（1.7.0）**：后台保存公告落库、前台渲染弹窗（announceModal/关闭按钮/记忆脚本、**横条形态已废弃**）、**关闭按钮携带内容 md5 hash（更新公告后所有访客自动重新看到）**、多行内容 nl2br、XSS 转义（`<script>`/`onmouseover` 注入不落地、仅以转义形态出现、hash 按原始内容计算）、公告更新 hash 失效、空公告完全不渲染、结束恢复默认 |
| `expire_test.php` | 41 | **商品有效期体系（1.7.0）**：后台保存有效期（值/单位落库、非法单位归一、0=永久）、`expireAt` 起算（+1 个月日历语义、7 天、**闰年边界**）、**网关与纯余额两条支付路径均写入 expire_at**、到期提醒 `remindDue`（**SMTP 未配置不发送不标记**、mock SMTP 发送成功并标记、幂等不再发、已过期不补发、解码后内容含订单号/到期日/备份文案）、后台到期管理（7 天内/已到期列表、控制台警示条、**标记已删机流转**）、用户订单页「剩余 N 天/已到期」、弹窗公告回归 |
| `mailer_wire_test.php` | 11 | **SMTP 报文线上字节级合规（1.5.5）**：raw fread mock 直接抓取线上字节（杜绝 fgets 行处理假象），断言无 `\r\r`（换行炸裂指纹）、无裸 LF、From/To/Subject/Date/MIME/Content-Type 头完整位于头区、线上字节与 `buildMessage`(+dot-stuffing) 输出全等——**回归防护 QQ `550 "From" header is missing` 类故障** |
| `captcha_test.php` | 32 | **图形验证码（1.5.3）**：4 位码生成（无易混淆字符）、Session 仅存哈希、不区分大小写、一次性防重放、过期即焚、空/非法输入拦截、GD 图片 PNG 签名与解析、端点 no-store 防缓存、sendCode 缺码/错码被拒、**开关保存联动注册页展示与接口校验、reg 组开关互不干扰** |
| `category_test.php` | 133 | **商品分类（1.7.8）**：① 归一化——图标 **16 键白名单**（注入串 `<script>` 归一空、大小写归一）、颜色仅 `#rgb/#rrggbb`（`red;drop table` 归一空）、名称空白折叠截断、状态归一。② slug——英文转写 / 符号归一 / **纯中文回落 `cat-{id}`** / 重名追加 `-2` / 编辑排除自身。③ CRUD——create 两步事务（先插行取 ID 再补 slug，无空 slug 中间态）、update 部分字段保留、`mapByIds` 防 N+1、展示辅助（nameOf/colorStyle/iconPath）。④ 计数——useCount 含下架 / activeUseCount 仅上架 / **activeWithCount 排除空分类与隐藏分类（showEmpty 放开空分类）** / Tab 计数=上架数。⑤ 后台 HTTP——未登录 302 防护、临时管理员登录、空名拒绝、表单回显（名称/颜色/图标选中）、toggle 往返、**move 相邻交换 + 同 sort 错开 ±1（上移真正变靠前）**、边界到顶不越界。⑥ 商品接入——表单下拉回显 selected、**保存传入不存在分类被拒**、列表按分类筛选（cat-badge / 未分类徽标）。⑦ 前台端到端——首页入口（有上架商品的启用分类才展示、计数、强调色）、Tab（含空分类、不含隐藏、激活态唯一）、分类筛选互斥、空分类空态、**不存在分类回落全部**、详情页分类标签（含强调色）与面包屑、未分类无标签、隐藏分类直达仍可见、**详情页无 PHP 代码字面残留**。⑧ 删除保护——无商品直删、有商品拒绝（文案含数量）、**force 删除商品置未分类且商品保留** |
| `donate_test.php` | 90 | **捐赠支付（1.7.9）**：① 上传端到端——multipart 合法 PNG 落库（随机命名 / 旧图清理 / 配置写入），**未登录 / 非法 which / txt 伪扩展 / 2.2MB 超限 / 30×30 小图五类拒绝**均不动配置，重复上传再清理上一张，removeDonate 清空并删文件。② 设置保存——donate 组开关与说明落库、**GROUP_SWITCHES 未勾选自动补 0**。③ Setting 归一——donateEnabled / donateDesc / donateQr（**非法 `hack`/`../hack`/空串一律返回空**、码文件丢失返回空、单侧丢失微信兜底、双侧无则 notReady）、donateReady 语义。④ 商品页——就绪时捐赠 radio **首位默认选中**、`#donateModal` 弹窗与双码渲染、关闭开关后不渲染。⑤ 下单与支付页——channel=donate 落库且**下单即占库存**、支付页双码 / 金额 / 说明 / 「我已完成付款」、**demo 区块对捐赠单隐藏**、白名单外通道（create 与 pay 两条路径）回落捐赠、官方 qr 未配置时失效单回落改写（已配置时回归正常渲染）。⑥ 声明付款——置 `user_claimed=1` + `claim_at`、重复声明幂等、**非本人 / 非捐赠通道拒绝**。⑦ 后台核验——列表「已声明付款」徽标、详情核验卡、**confirmDonate → DELIVERED + `DONATE-` 交易号 + 库存绑定 + 销量 +1**、重复确认幂等拒绝、核验后核验卡消失。⑧ 关闭驳回——close 释放库存（未售 + 解绑）、关闭后声明与确认均被状态门禁拒绝、已发货单访问支付页 302 跳详情。⑨ 回归——易支付通道共存（支付页含 demo 区块、无捐赠区块）、demo 开关差分。⑩ 清理自检——订单/库存/商品/用户/管理员/上传图/配置快照全量还原 |
| `free_test.php` | 51 | **0 元商品免费领取（1.7.10）**：① 后台保存——price=0 落库 0.00、**负数拒绝**（「售价不能为负数（填写 0 表示免费领取）」）。② 商品页——0 元显示「免费领取」与保障文案、**支付方式区整体隐藏（无任何 pay_channel 单选）**、**优惠券选择区隐藏**（0 元无可折金额）、付费商品页支付区对照保留。③ 0 元下单即发货——下单 302 直达详情（不进支付页）、**channel=free + DELIVERED + `FREE-` 交易号 + 库存分配绑定 + 销量 +1**、**不扣余额零消费流水**、详情页「免费领取成功」与面板信息。④ 券后 0 元——满 10 减 10 券叠加 10 元商品 → amount/coupon_discount/pay_amount 三段账实、**free + DELIVERED + 券核销（used_count+1）**、零余额用户不产生负余额、已核销券重复下单被拒。⑤ 限购计入——0 元 DELIVERED 订单占限购名额、二次下单「无法再次购买」。⑥ 防呆与文案——free 单访问 pay 页 302 跳详情、`pay_channel_text('free')`/`short('free')`、后台列表「免费」/详情「免费领取（0 元）」/前台「支付方式：免费」。⑦ 回归——付费 yipay 单保持 PENDING 不被误判、**纯余额 BAL 前缀与真实扣款流水**（free 分支未污染既有结算判定）。⑧ 清理自检——订单/库存/商品/券/流水/用户/管理员/配置快照全量还原 |
| `theme_test.php` | 54 | **双主题·蓝白猫娘（1.7.11）**：① 色板结构——`:root`（樱花粉）与 `html[data-theme="bluecat"]`（蓝白）两块均解析、**各含全部 30 项关键变量**（品牌色/底色/文字/阴影/插画/光斑），蓝白块覆盖全部**主题相关性变量**（圆角与字体等结构性变量保持继承不改写，切换无布局跳动）。② 基调正确性——主色为**合法 HEX 蓝色系（B>R 且 B>G 且高亮）**、底色冷白（B≥R 且亮度>240）、默认主色仍为樱花粉（R>B）、两主题主色与插画路径互异。③ 插画变量化——**6 处插画（Hero/商品底纹/登录 chibi/空状态/流程区块/余额页）全部改由 `var(--img-*)` 驱动**、全站背景光斑变量化（`--glow-1/3`）、**无残留硬编码插画路径**。④ 素材——蓝白 6 张 WebP 齐全、体积合理（>8KB）、**RIFF/WEBP 魔数合法**、默认主题 7 张旧插画保留（回切无缺图）。⑤ 前台渲染——首页 200、`<html>` 无初始 data-theme（默认即樱花粉）、**切换按钮双图标 + 文案槽**、**防闪烁预设脚本位于 `<head>` 内且早于 `<body>`** 并含 bluecat 分支、localStorage 键 `ly_theme` 读写、**值白名单仅 sakura/bluecat**、点击事件绑定、商品页与登录页同样带入口。⑥ CSS 联动——按 `data-theme` 控制猫脸/樱花图标显隐、切换按钮基础样式、**0.28s 过渡动画**。⑦ 静态资源——app.css 与蓝白插画 HTTP 200（**防路由吞静态文件**）、线上 CSS 含蓝白主题块。⑧ 隔离与回归——后台样式不引用蓝白插画、后台登录页无前台切换按钮、默认主题 6 条既有插画规则完整（重构未破坏）、页面无 PHP 报错残留 |
| `web_flow_test.sh` | 57 | 下单 → 支付 → 自动发货 → 面板信息展示全链路、**白名单拦截**、**验证码 AJAX 接口**、**找回密码防枚举**、**后台邮件设置 Tab**、CSRF、越权、后台访问控制 |

> 新增/修改功能后建议全跑一遍。`smtp_test.php`、`balance_test.php`、`coupon_test.php` 等会在结束时**自动还原被改动的配置与测试数据**，可放心在线上环境执行。

---

## 14. 版本升级

### 1.7.5 → 1.7.6

**新增：商品对接 MNBT 自动开通主机**

```bash
cd /www/wwwroot/lycloud

# 1) 备份数据库
mysqldump -u root -p lycloud > /root/lycloud_bak_$(date +%F).sql

# 2) 覆盖代码（保留 config/config.php），然后执行升级脚本
mysql -u root -p lycloud < install/upgrade_1.7.6.sql

# 3) 后台配置接口：系统设置 → MNBT对接，填完后点「测试连接」
```

升级脚本**幂等可重复执行**，全部字段走 `information_schema` 存在性判断；
存量商品自动回填 `deliver_mode=1`（库存池卡密），**行为与升级前完全一致、零影响**。

**本次变更**

| 类型 | 内容 |
| --- | --- |
| 数据库 | `ly_products` +7 列、`ly_stocks` +2 列、`ly_orders` +4 列、`ly_orders` +1 索引（见 `install/upgrade_1.7.6.sql`） |
| 新增类 | `app/Mnbt/MnbtClient.php`（MNBT API 客户端，零依赖） |
| 新增页面 | 后台「系统设置 → MNBT对接」配置页（含测试连接）；前台/后台订单详情「主机自动开通」卡片 |
| 新增路由 | `admin/setting/testMnbt`、`order/mnbtlogin`、`admin/order/retryMnbt`、`admin/order/mnbtLogin` |
| 新增测试 | `tests/mnbt_test.php`（210 项，自带 mock MNBT 服务） |

> **重要**：MNBT 的鉴权参数（`mn_key` / `mn_keye`）以**明文**随每次请求传输，官方协议无签名机制。
> **生产环境务必给本站与 MNBT 服务都启用 HTTPS**，否则密钥可能被中间人截获。

---

### 1.7.4 → 1.7.5

**界面改版：全站「樱花粉 + 白」二次元柔和风**

纯模板与静态资源层改动，**无任何逻辑变更、无数据库变更**。

**配色方案**

| 用途 | 变量 | 值 |
| --- | --- | --- |
| 主品牌色 | `--primary` | `#ff8aa8`（樱花粉） |
| 主色加深 | `--primary-dark` / `--primary-deep` | `#f56f92` / `#e05a7e` |
| 主色浅调 | `--primary-soft` | `#ffb7c5` |
| 页面底色 | `--bg` / `--bg-soft` / `--bg-pink` | `#fffafb` / `#fff4f7` / `#ffeff3` |
| 卡片 | `--card` / `--card-hover` | `#ffffff` / `#fffafc` |
| 边框 | `--border` / `--border-soft` | `#ffe0e9` / `#fff0f4` |
| 正文 / 次要 / 弱化 | `--text` / `--text-sub` / `--text-dim` | `#4a3a42` / `#8d7680` / `#b6a1aa` |

圆角从 `6px` 提升到 `16px`（按钮改为全圆角胶囊 `999px`），阴影由黑色调改为**粉调柔光**（`rgba(255,138,168,.16~.38)`）。

**视觉要点**

- **全站樱花柔光背景**：`body` 用三处粉色径向渐变光斑叠加（左上/右上/底部），`background-attachment: fixed`
- **导航头**：半透明毛玻璃（`backdrop-filter: blur(14px)`）+ 粉调描边，Logo 换成樱花钱包图标
- **首页 Hero**：右侧铺开 AI 生成的主视觉插画，用 `mask-image` 渐隐融入底色；标题「IPv6 宝塔面板主机」改为粉→紫渐变文字；Hero 徽标改胶囊带光点呼吸动画
- **商品卡**：悬停上浮 + 顶部渐显粉紫条，价格用樱花粉大字号
- **表单**：输入框聚焦时粉色描边 + `0 0 0 4px` 柔光光环
- **优惠券票根**：左侧票根粉色渐变底 + 虚线齿孔 + 上下半圆缺口，右侧票据区内容分层
- **弹窗**：顶部 5px 粉紫渐变条，关闭按钮悬停旋转变粉底白叉
- **后台**：侧边栏白→浅粉渐变，选中项粉底胶囊 + 内阴影描边；表格启用**粉调斑马纹** + 悬停整行高亮；统计卡片悬停上浮；柱状图改粉色渐变圆柱；状态进度条改胶囊圆角 + 渐变填充；后台登录页铺樱花纹理背景
- **空状态**：`.empty-state` 自动显示空状态插画（纯 CSS `::before`，无需改视图）
- **错误页**：404 数字改粉紫渐变文字

**新增静态资源**（`assets/img/`）

| 文件 | 尺寸 | 用途 |
| --- | --- | --- |
| `hero-sakura.webp` / `.jpg` | 779×1051 | 首页 Hero 主视觉（1.7.7 竖构图·樱花飘瓣） |
| `auth-chibi.webp` / `.png` | 620×620 | 登录/注册页装饰（持锁与钥匙的樱花小精灵，悬浮呼吸动画） |
| `auth-cosmos.webp` / `.jpg` | 636×900 | 登录/注册页左侧陪衬（星空兔耳角色，向内渐隐） |
| `pd-maid.webp` / `.jpg` | 700×958 | 商品详情页氛围底纹（女仆角色，右上角 20% 透明） |
| `bal-guitar.webp` / `.jpg` | 758×1080 | 余额总览右侧点缀（抱吉他角色） |
| `bal-kotori.webp` / `.jpg` | 820×951 | 首页开通流程区块右侧装饰（花鸟拼贴角色） |
| `empty-state.webp` / `.png` | 700×700 | 空状态插画（云朵上的小樱娘 + 空购物箱） |
| `admin-bg.webp` / `.jpg` | 1400×965 | 后台登录页樱花纹理背景 |
| `logo-mark.png` / `@2x` / `favicon.png` | 128 / 256 / 512 | 樱花钱包 Logo 图标（程序化绘制） |
| `favicon.ico` | 多尺寸 | 站点图标（16/32/48/64/128） |

> 插画均经二次量化压缩，提供 **WebP**（现代浏览器优先）与 **PNG/JPEG** 双份兜底。1.7.7 的 5 张新素材 WebP 主用部分合计约 430 KB（浏览器实际只加载 WebP，JPEG 仅作兜底），5 张原图合计 4.4 MB → 压缩后约 1.1 MB。1.7.5 的横构图 `hero-girl` 在 1.7.7 被 `hero-sakura` 取代并**已从包中移除**。`favicon.ico` 放在站点根目录。

**1.7.7 插画落位与融合手法**

5 张新增素材**全部为竖构图**（宽高比 0.687–0.719，接近 A4 直幅），与 1.7.5 的横构图 `hero-girl` 用法完全不同。由于素材本身**不带透明通道**（`dress.png` 虽是 RGBA 格式，但 alpha 通道全为 255，实际不透明），无法靠图片自身透明度自然融入，全部改用 **CSS `mask-image` 渐隐** 实现边缘消融：

| 落位 | 素材 | CSS 挂载点 | 融合手法 | 桌面 | 平板(≤1024px) | 手机(≤768px) |
| --- | --- | --- | --- | --- | --- | --- |
| 首页 Hero | `hero-sakura` | `.hero::after` | 左上向内渐隐 + `right bottom` 锚定；`.hero::before` 叠一层底色渐变柔光 | `min(30vw,430px)`，`opacity .97` | 宽 50%，`opacity .15`，下移出血 | 宽 86%，`opacity .17` |
| 首页开通流程 | `bal-kotori` | `.steps-section::before` | 右侧锚定 + 向左渐隐 | `min(38vw,500px)`，`opacity .14` | 同上 | 同上 |
| 登录/注册 | `auth-cosmos` | `.auth-page::after` | `left bottom` 锚定 + 向右渐隐 + 底部渐隐 | `min(25vw,340px)`，`opacity .46` | 隐藏（`display:none`） | 隐藏 |
| 商品详情 | `pd-maid` | `.pd-card::after` | 右上角锚定 + 左下双向渐隐，仅作氛围底纹 | 300×340，`opacity .2` | 同上 | 同上 |
| 余额总览 | `bal-guitar` | `.bal-hero::after` | 右侧锚定 + 向左渐隐；`.bal-hero-stats` 加 `margin-right` 预留空间 | 宽 210px，`opacity .38` | 同上 | 宽 170px，`opacity .26`，`margin-right: 96px` |

**关键取舍**

- **Hero 是唯一有横向冲突的位置**：`.hero-spec`（交付内容卡）占据右侧 `0.9fr`（约 484px），而竖构图人物头部在图片顶部。经实测调整后改为「右下角锚定 + 收窄到 `min(30vw,430px)` + 容器 `min-height: 520px`」，使人物**头部完整露出在卡片上方**，身体沿右边缘向下自然延伸；卡片补一层 `rgba(255,255,255,.82)` 白纱 + 外圈 `0 0 0 6px` 柔光，避免与插画硬碰。
- **平板端（≤1024px）是危险区**：布局转为单列，插画会盖住 `hero-stats` 数字。已把 `hero::after` 压到 `opacity .15` 并向下出血，实测数字完全可读后才定稿。
- **登录页左侧插画在 ≤1024px 直接隐藏**，而不是降透明度——窄屏下留白不足，任何可见的插画都会让表单显拥挤。
- **余额总览用布局预留而非纯遮罩**：`mask-image` 只能让图片变淡，不能阻止它压在「累计充值/累计消费/累计返还」三个统计数字下面，因此额外给 `.bal-hero-stats` 加 `margin-right: 150px`（手机 96px）真正让出空间。

**移动端适配**

- 登录/注册页：右侧樱花小精灵在 ≤768px 时移到表单卡片**上方**（`order: -1`）；**左侧星空陪衬插画在 ≤1024px 直接隐藏**
- Hero 插画：平板端淡化到 15%、手机端 17%，作为背景纹理，不干扰文字阅读
- 余额总览插画：手机端收窄到 170px 并淡化到 26%，统计数字预留 96px 右间距
- 后台表格卡片化后**取消斑马纹**，避免与卡片底色冲突

**修复**

- **导航文字竖排 Bug**：`.main-nav a` 缺少 `white-space: nowrap` / `flex: 0 0 auto`，登录后导航项增多时被挤成逐字竖排。已在 `769px ~ 1180px` 区间增加内边距压缩断点，保证导航项完整横排

**升级方式**：直接覆盖代码文件（保留 `config/config.php`）即可，**无需执行任何 SQL**。由于静态资源版本参数使用文件修改时间指纹（1.5.2 引入），覆盖后浏览器缓存自动失效。

本次为纯样式改版，无新增测试项；全量 19 套测试 1308 项断言全部通过。

---

### 1.7.12 → 1.7.13（本次版本）

**MNBT 3xx 重定向诊断：修复「接口返回格式异常：301 Moved Permanently cloudflare」**

线上实际案例：上游 MNBT 站点套了 Cloudflare 并开启强制 HTTPS，管理员把接口地址填成 `http://mnbt.xxx.best` 后，所有请求被 301 跳转到 `https://`。由于 `MnbtClient` 不跟随重定向，拿到的是跳转页 HTML（`301 Moved Permanently ... cloudflare`），被误报为「接口返回格式异常」；购买链路重试耗尽后自动退款，用户侧显示「主机开通失败，已退款」。

| 环节 | 说明 |
| --- | --- |
| 诊断增强 | `MnbtClient::post()` 对 HTTP 301/302/307/308 **不跟随**（POST 跟随后会转 GET 且掩盖配置错误），直接抛出可行动异常：标注 `HTTP 301 重定向（未跟随）`；利用 `CURLINFO_REDIRECT_URL` 携带重定向目标；**检测到 http → https 跳转时明确提示「把接口地址改为 https:// 开头后保存重试」**；其他跳转提示核对接口地址 |
| 后台提示 | 「参数从哪来」与接口地址输入框两处说明补充：站点若开启强制 HTTPS（如 Cloudflare），必须填 `https://` 开头，填 http 会收到 301 导致对接失败 |
| 资损说明 | 该案例中「重试 2 次 → 自动退款到余额」为 1.7.6 预期兜底行为；地址修正后重新购买即可，已退款订单不会自动恢复 |

**改动文件**

| 文件 | 改动 |
| --- | --- |
| `app/Mnbt/MnbtClient.php` | curl 分支新增 3xx 检测与带重定向目标的诊断异常（`CURLINFO_REDIRECT_URL`） |
| `app/Views/admin/setting.php` | 接口地址两处说明补强制 HTTPS 警示 |
| `tests/mnbt_test.php` | 新增第八节 6 项断言（301 mock 场景：标注 301 / 不误报格式异常 / 携带目标 / 改址指引） |

**升级方式**：直接覆盖代码文件（保留 `config/config.php`）即可，**无需执行任何 SQL**。

本次全量 23 套测试 **1659 项断言全部通过**（新增 6 项）。

---

### 1.7.11 → 1.7.12

**MNBT 对接易用性修复：测试连接用表单当前值 + mn_vs 兼容 v1.82**

修复管理员反馈的「MNBT 配置填写正确，但点『测试连接』没反应、也无法购买（MNBT 1.82）」问题。排查确认三个叠加根因，一并修复：

| # | 根因 | 修复 |
| --- | --- | --- |
| 1 | 「测试连接」只读**已保存**配置——刚改完表单没点「保存配置」就测试，测的仍是旧配置 | `SettingController::mergeMnbtFormConfig()`：前端把表单当前值一并 POST，后端**表单值优先**合并（密钥输入框留空 = 沿用已保存密钥、未提交字段沿用已保存值、`api_url` 裁剪 / `mn_vs` 归一 / 超时钳制与保存逻辑一致）。**改完即可测试，无需先保存** |
| 2 | `mn_vs` 提示只举例到 v1.7，v1.82 这类**两位小数**版本没有说明；且 `code=300` 报错只说「版本不匹配」不告诉用户当前填了什么 | 后台两处提示补全「**v1.82 填 182**（直接填 1.82 也会自动转为 182）」示例；`MnbtClient` 在 `code=300` 时报错**携带当前提交的 `mn_vs` 值 + 正确填写指引 + 上游原文**——后台测试结果与订单 `deliver_error` 均可直接定位 |
| 3 | MNBT 站点不可达时 curl 等满「连接超时 + 总超时」最长约 25 秒，按钮只显示「测试中…」体感像没反应 | 按钮即时反馈改为「测试中…最长约 25 秒」+ 结果区先显示「正在连接 MNBT 接口，请耐心等待…」；新增前端 **35 秒兜底超时**（AbortController），超时给出明确提示；按钮下方常驻说明「无需先保存 / 留空沿用密钥 / 最长 25 秒」 |

> 关于「无法购买」：MNBT 商品购买（支付成功 → 实时开通）链路本身在 1.7.6 已健全——开通失败会进入「待重试」并落库 `deliver_error`，重试耗尽自动退款到余额。此前表现「无法购买」多为 `mn_vs` 版本不匹配（`code=300`）或站点不可达；本版本修复后，失败原因会在后台订单详情与用户订单页**直接显示具体原因与指引**，按提示修正版本号即可恢复开通。

**改动文件**

| 文件 | 改动 |
| --- | --- |
| `app/Controllers/admin/SettingController.php` | 新增 `mergeMnbtFormConfig()`（表单值 × 已保存配置合并归一）；`testMnbt()` 改为优先用 POST 表单值构造 MnbtClient |
| `app/Mnbt/MnbtClient.php` | `code=300` 报错文案增强：携带当前 `mn_vs` 值、v1.6/1.7/1.82 填写示例与上游原文 |
| `app/Views/admin/setting.php` | 两处 `mn_vs` 提示补 v1.82 → 182 示例、placeholder 更新；测试连接 JS 收集表单字段一并 POST、按钮/结果区即时反馈文案、35s 兜底超时、无需先保存说明 |
| `tests/mnbt_test.php` | 新增第七节 17 项断言（合并规则单测 + 小数归一 + 端到端表单值优先与 code=300 报错内容） |

**升级方式**：直接覆盖代码文件（保留 `config/config.php`）即可，**无需执行任何 SQL**。静态资源带文件修改时间指纹，覆盖后浏览器缓存自动失效。

本次全量 23 套测试 **1653 项断言全部通过**（新增 17 项）。

---

### 1.7.10 → 1.7.11

**双主题 · 蓝白猫娘**

新增第二套视觉主题「蓝白猫娘」：浅蓝长发 + 猫耳 + 白裙 + 黑白猫元素的淡蓝渐变基调，与默认「樱花粉」并存，访客可在前台一键切换。

| 环节 | 说明 |
| --- | --- |
| 主题机制 | 全部视觉令牌抽为 CSS 变量：默认写在 `:root`（樱花粉），蓝白主题写在 `html[data-theme="bluecat"]` 覆盖——**色板 / 文字 / 阴影 / 全站背景光斑 / 插画路径**一并替换，切换零重排 |
| 切换入口 | 前台右上角主题按钮（🌸 樱花粉 ⇄ 🐱 蓝白猫娘），图标与文案随当前主题联动；移动端自动收成纯图标 |
| 偏好记忆 | 写入 `localStorage.ly_theme`（键值白名单仅 `sakura` / `bluecat`）；**`<head>` 内联预置脚本在 `<body>` 之前同步套用**，首屏即为正确主题，无「先粉后蓝」闪烁 |
| 插画 | 配套生成 6 张同风格猫娘插画（首页竖版主视觉 / 商品页思考姿 / 登录页 chibi / 空状态卧姿猫爪 / 流程区块横版 / 余额页抱猫），WebP 输出存于 `assets/img/bluecat/`，总体积约 0.9MB |
| 插画变量化 | 原 6 处硬编码 `url('../img/xxx.webp')` 全部改为 `var(--img-*)`；全站背景三处光斑改为 `var(--glow-1/2/3)`，使主题切换能一并换图换光 |
| 视觉强度 | 柔和淡蓝——白底 + 淡蓝渐变点缀，猫耳 / 猫爪 / 蝴蝶结作为轻装饰，保持商务可读性；切换加 0.28s 柔和过渡避免刺眼跳变 |
| 作用范围 | **纯前台**。访客偏好仅作用于前台页面，后台样式与界面不受影响；纯 CSS + 少量 JS，**无数据库变更** |

**改动文件**

| 文件 | 改动 |
| --- | --- |
| `assets/css/app.css` | `:root` 重构为「默认色板 + 插画变量 + 光斑变量」；新增 `html[data-theme="bluecat"]` 完整覆盖块；6 处插画引用与 body 光斑改为变量；新增 `.theme-toggle` 样式与图标显隐规则、切换过渡动画 |
| `app/Views/layout/header.php` | `<head>` 新增防闪烁主题预置脚本；`header-actions` 新增主题切换按钮（双图标 + 文案槽）；页尾新增切换逻辑 JS（读取并写入 localStorage + 点击切换 + 标签同步） |
| `assets/img/bluecat/*` | **新增** 6 张蓝白猫娘插画（WebP） |
| `tests/theme_test.php` | **新增** 54 项测试套件 |

**升级方式**：直接覆盖代码文件（保留 `config/config.php`）即可，**无需执行任何 SQL**（本版本无数据库结构变更）。静态资源带文件修改时间指纹，覆盖后浏览器缓存自动失效。

```bash
cd /www/wwwroot/lycloud
mysqldump -u root -p lycloud > /root/lycloud_bak_$(date +%F).sql
# 覆盖代码（保留 config/config.php 与 runtime/、uploads/），无 SQL 需要执行
```

本次全量 23 套测试 **1636 项断言全部通过**（新增 54 项）。

---

### 1.7.9 → 1.7.10

**0 元商品免费领取**

商品售价支持 0 元：后台可保存 0 价商品，买家下单**免支付直接自动发货**。同时修复了「0 元订单永久卡在待支付」的潜在问题——无论是商品原价 0 元，还是**优惠券抵扣后恰好 0 元**（如满 10 减 10 叠加 10 元商品），此前都会落入支付页并被「该订单无需在线支付」踢回，订单永远无法完成。

| 环节 | 说明 |
| --- | --- |
| 后台保存 | 售价校验放宽为**拒负数**（0=免费领取），表单输入放开 `min=0` 并提示「填写 0 表示免费领取，下单后无需支付直接自动发货」 |
| 前台展示 | 0 元商品价格区显示「免费领取」、保障区显示「0 元商品，免支付直接发货」，**支付方式选择与优惠券区块整体隐藏**（0 元无可折金额），购买按钮文案随动 |
| 下单结算 | `createWithStock` 识别 `pay_amount=0 且 balance_paid=0` → 新增 **`free` 支付通道**，复用纯余额结算路径（置 PAID → doDeliver 自动发货：库存分配 / MNBT 开通 / 销量计数）但**不扣余额、零消费流水**；交易号 `FREE-{订单号}` 与 `BAL`（余额单）明确区分 |
| 券后 0 元 | 「先券后余额」计算链路自然覆盖：满减券把金额清零后同样自动走 free 通道即时发货，券正常核销（used_count+1），零余额用户不产生负余额 |
| 控制器 | `order/create` 对 free 单直接提示「免费领取成功」+ 异步发通知邮件 + 跳订单详情（与纯余额同策略）；`order/pay` 对 free 通道防呆跳转 |
| 通道文案 | `pay_channel_text('free')` = 免费领取（0 元）、`pay_channel_text_short('free')` = 免费，前后台订单列表 / 详情自动生效 |
| 限购 | 0 元 DELIVERED 订单正常计入每人限购次数，防止反复白嫖占库存 |

**改动文件**

| 文件 | 改动 |
| --- | --- |
| `app/Controllers/admin/ProductController.php` | 售价校验 `<= 0` → `< 0`，允许 0 元、拒绝负数，文案同步 |
| `app/Views/admin/product_form.php` | 价格输入 `min=0` + 「0=免费领取」提示 |
| `app/Models/Order.php` | `createWithStock` 新增 `$freeOrder` 判定与 free 通道落库；`settleBalanceOrder` 增加 `FREE-` 交易号前缀参数（不扣余额复用发货链路） |
| `app/Controllers/OrderController.php` | `create()` 结算直跳分支与 `pay()` 防呆分支纳入 `free` |
| `app/helpers.php` | `pay_channel_text()` / `pay_channel_text_short()` 新增 `free` 分支 |
| `app/Views/home/product.php` | 0 元价格展示、支付方式与优惠券区块条件隐藏、按钮与保障文案随动 |
| `tests/free_test.php` | **新增** 51 项测试套件 |

**升级方式**：直接覆盖代码文件（保留 `config/config.php`）即可，**无需执行任何 SQL**（本版本无数据库结构变更）。

```bash
cd /www/wwwroot/lycloud
mysqldump -u root -p lycloud > /root/lycloud_bak_$(date +%F).sql
# 覆盖代码（保留 config/config.php 与 runtime/、uploads/），无 SQL 需要执行
```

本次全量 22 套测试 **1582 项断言全部通过**（新增 51 项）。

---

### 1.7.8 → 1.7.9

**捐赠支付 · 人工核验发货**

新增第四种支付通道「捐赠扫码」：无网关回调，买家扫码付款后主动声明，管理员核实到账后一键确认并自动发货。适合不便接入支付接口的个人站点。

| 环节 | 说明 |
| --- | --- |
| 后台配置 | 「系统设置 → 捐赠收款码」页签：启用开关 + 收款说明 + **支付宝 / 微信双收款码上传**（JPG/PNG/WebP ≤2MB、`getimagesize` 真图 ≥50×50、文件名全随机、旧图自动清理、可移除） |
| 下单默认选中 | 双码任一就绪即「捐赠就绪」，下单页通道列表**首位为捐赠并默认勾选**；点击 radio 弹出双码弹窗（遮罩 / 关闭按钮 / Esc 关闭），弹窗含说明与双码 |
| 支付页 | 展示双码 + 金额提示 + 收款说明；买家付款后点**「我已完成付款」**声明——订单标记 `user_claimed=1`（幂等），状态保持待支付；已声明后显示绿色「已声明付款」卡片 |
| 后台核验 | 订单列表「已声明付款」徽标；订单详情「捐赠收款核验」卡（声明状态 / 声明时间 / 金额）；**「确认收款并发货」**复用 markPaid 幂等链路：库存分配 / MNBT 开通 / 通知邮件一气呵成 |
| 交易号 | `DONATE-{订单号}` 前缀，与真实网关回调（易支付流水号 / 支付宝交易号）明确区分 |
| 驳回路径 | 未到账直接「关闭订单」——释放库存；关闭后声明与确认均被状态门禁拒绝 |
| 回落语义 | 订单通道失效（如后台停用）时回落通道列表首位；捐赠就绪时即回落捐赠（支付页自动改写订单通道） |

**关键设计**

- **就绪判定**：`donateReady = 开关开启 && 至少一张码文件真实存在`——文件被误删自动退出通道，不出现裂图下单
- **路径安全**：`donateQr()` 仅接受 `ali/wechat` 两个键值，含 `..` 或文件不存在的路径一律返回空串；上传文件名完全随机（不采信用户输入），扩展名走白名单
- **开关互踩防护**：donate 组开关走 GROUP_SWITCHES 按表单归属补 0，与 mail/reg 组同一机制，保存其他页签不会误关捐赠
- **幂等**：声明可重复提交（状态不变）；确认收款基于行锁 + 状态判断，重复点击 / 并发回调均不会二次发货

**改动的文件**

| 类型 | 文件 | 改动 |
| --- | --- | --- |
| 数据库 | `install/schema.sql` | `ly_orders` 新增 `user_claimed` / `claim_at` 列；默认配置新增捐赠 4 键 |
| 数据库 | `install/upgrade_1.7.9.sql` | **新增**幂等升级脚本（information_schema 判断 + PREPARE 执行 + 配置 INSERT IGNORE，可重复执行） |
| 模型 | `app/Models/Setting.php` | 新增 `donateEnabled` / `donateDesc` / `donateQr`（路径安全）/ `donateReady` |
| 模型 | `app/Models/Order.php` | 新增 `claimDonate`（归属 + 通道 + 状态四重 WHERE 幂等置位） |
| 控制器 | `app/Controllers/admin/SettingController.php` | 配置白名单与开关组接入捐赠；save 支持捐赠页签回跳；**新增** `uploadDonate` / `removeDonate` |
| 控制器 | `app/Controllers/OrderController.php` | `allowedChannels()` 捐赠就绪时排首位；`pay()` 新增捐赠渲染分支；**新增** `claimDonate` |
| 控制器 | `app/Controllers/admin/OrderController.php` | **新增** `confirmDonate`（门禁 → markPaid → 日志） |
| 控制器 | `app/Controllers/HomeController.php` | 商品详情页传捐赠就绪状态 / 双码路径 / 说明 |
| 视图 | `app/Views/admin/setting.php` | Tab 导航新增「捐赠收款码」；捐赠页签（开关 / 说明 / 双码上传盒 / 预览 / 移除） |
| 视图 | `app/Views/admin/order_detail.php` | 捐赠单操作卡改为「捐赠收款核验」（声明状态 + 确认收款并发货 + 关单提示） |
| 视图 | `app/Views/admin/order_list.php` | 通道列新增「已声明付款」徽标（仅待支付 + 已声明时展示） |
| 视图 | `app/Views/home/product.php` | 通道首位捐赠 radio（默认选中）+ `#donateModal` 双码弹窗（含 JS 交互） |
| 视图 | `app/Views/home/pay.php` | 通道切换含「捐赠扫码」；捐赠支付主体（双码 grid / 说明 / 声明按钮 / 已声明卡）；demo 区块对捐赠单隐藏 |
| 辅助 | `app/helpers.php` | `pay_channel_text_short` 新增 `donate → 捐赠` |
| 路由 | `app/Router.php` | 新增 `admin/setting/uploadDonate`、`admin/setting/removeDonate`、`order/claimDonate`、`admin/order/confirmDonate` |
| 样式 | `assets/css/app.css` / `admin.css` | 捐赠弹窗全套 / 支付页捐赠区 / 已声明卡；后台双码上传盒与响应式 |
| 测试 | `tests/donate_test.php` | **新增** 90 项测试套件（上传端到端与五类拒绝 / Setting 归一 / 默认选中 / 下单支付页 / 声明幂等与门禁 / 核验自动发货 / 关闭驳回 / 回归与 demo 隔离 / 现场还原自检） |

**升级方式**

```bash
# 1) 覆盖代码文件（保留 config/config.php）
# 2) 执行升级脚本（幂等，可重复执行）
mysql -u lycloud -p lycloud < install/upgrade_1.7.9.sql
```

升级后到后台「系统设置 → 捐赠收款码」开启开关并上传支付宝 / 微信收款码即可；不上传则捐赠通道不出现，前台一切照旧。

本次全量 21 套测试 **1531 项断言全部通过**（新增 90 项；过程中为 `donateQr()` 补上非法通道键返回空串的加固断言，`web_flow_test.sh` 的「收银台含支付入口」断言同步扩展识别捐赠入口「我已完成付款」）。

---

### 1.7.7 → 1.7.8

**商品分类体系**

新增完整的商品分类能力：后台「分类管理」+ 商品单选归属 + 前台 Tab 筛选与首页快捷入口。

| 前台形态 | 说明 |
| --- | --- |
| 全部商品页分类 Tab | 「全部 + 各分类」pill 导航，带图标 / 上架商品计数 / 强调色；空分类保留 Tab（点击显示空态引导而非消失）；隐藏分类不出现在 Tab |
| 首页分类快捷入口 | 卡片式入口（图标 + 名称 + 描述 +「N 款」计数），**仅展示有上架商品的启用分类**，避免点进空页 |
| 商品详情页 | 标签区首位显示可点击的分类标签（带强调色），面包屑加入分类层级 |
| 隐藏分类语义 | 隐藏（停用）只是**不展示入口**，直达 URL 仍可浏览商品——不等于下架 |

**后台「分类管理」**

- 增删改 + 启停 + 相邻上移/下移（`sort DESC, id ASC` 口径；同 sort 值时自动错开 ±1 保证顺序真正变化）
- 名称、slug（留空自动生成）、描述、图标（**16 个内置 SVG 图标白名单**：server/cloud/bolt/globe/shield/card/gift/star/clock/tag/chip/database/fire/heart/cube/layer）、强调色（取色器 + HEX 文本框，仅接受 `#rgb/#rrggbb`）
- 商品管理列表新增「分类」列与分类下拉筛选；商品表单新增分类单选下拉（编辑正确回显）
- 统计行展示分类总数 / 启用数 / 未分类商品数

**关键设计**

- **slug 策略**：留空由名称自动生成（保留 `[a-z0-9]`）；纯中文名转写失败回落 `cat-{id}`；重名自动追加 `-2` `-3`；新建走「先插入取 ID、再事务内补 slug」两步，避免空 slug 中间态
- **安全白名单**：图标键不在白名单一律归一空（杜绝任意字符串被当 SVG 渲染）、颜色仅 HEX、名称/描述按上限截断
- **删除保护**：分类下仍有商品时默认拒绝（文案提示数量）；确认强制删除后**事务内先把旗下商品置为未分类**（商品本身不删，避免误删数据）
- **不存在分类回落**：前台传不存在的分类 ID 自动回落「全部」，避免空页

**改动的文件**

| 类型 | 文件 | 改动 |
| --- | --- | --- |
| 数据库 | `install/schema.sql` | 新增 `ly_categories` 表；`ly_products` 增加 `category_id` 列与 `idx_category` 索引 |
| 数据库 | `install/upgrade_1.7.8.sql` | **新增**幂等升级脚本（表 / 列 / 索引均走 information_schema 判断，可重复执行） |
| 模型 | `app/Models/Category.php` | **新增**：归一化 / slug / 校验 / 查询（`activeWithCount` / `mapByIds` 防 N+1）/ CRUD / 删除保护 / 启停 |
| 控制器 | `app/Controllers/admin/CategoryController.php` | **新增**：listing / form / save / toggle / delete（force）/ move |
| 控制器 | `app/Controllers/admin/ProductController.php` | 列表分类筛选与徽标数据、表单注入分类下拉、保存校验分类存在性 |
| 控制器 | `app/Controllers/HomeController.php` | 首页传分类入口、列表页解析 category 参数（不存在回落全部）、详情页传所属分类 |
| 视图 | `app/Views/admin/category_list.php` / `category_form.php` | **新增**：分类列表（统计行 / 徽标 / 移位操作）与编辑表单（图标选择器 / 取色器） |
| 视图 | `app/Views/admin/product_list.php` / `product_form.php` | 列表分类列 + 筛选下拉；表单分类下拉与「管理分类」链接 |
| 视图 | `app/Views/home/listing.php` / `index.php` / `product.php` | 列表页重写（面包屑 / 分类 Tab / 空态）；首页分类快捷入口卡；详情页分类标签与面包屑 |
| 布局 | `app/Views/layout/admin_header.php` | 菜单新增「分类管理」 |
| 路由 | `app/Router.php` | 新增 `admin/category` 路由组（list/form/save/toggle/delete/move） |
| 样式 | `assets/css/app.css` / `admin.css` | `.cat-tabs`/`.cat-tab`、`.cat-entry` 快捷入口、`a.tag-cat`；后台 `.cat-name-cell`/`.cat-badge`/`.cat-icon-picker` 等 + 三档响应式 |
| 测试 | `tests/category_test.php` | **新增** 133 项测试套件（模型归一 / slug / CRUD / 计数 / 后台 HTTP / 商品接入 / 前台端到端 / 删除保护） |

**升级方式**

```bash
# 1) 覆盖代码文件（保留 config/config.php）
# 2) 执行升级脚本（幂等，可重复执行）
mysql -u lycloud -p lycloud < install/upgrade_1.7.8.sql
```

升级后到后台「商品管理 → 分类管理」创建分类，再到商品编辑页选择归属即可；不选择分类的商品保持「未分类」，前台一切照旧。

本次全量 20 套测试 **1441 项断言全部通过**（新增 133 项；测试过程中发现并修复了详情页视图一处 PHP 块断裂回归与分类排序同 sort 错开方向问题，套件中已加入「页面无 PHP 代码字面残留」与「同 sort 上移真正变靠前」防回归断言）。

---

### 1.7.6 → 1.7.7

**主题插画扩充：5 张竖构图素材落位**

新增 5 张二次元插画，用于替换/补充 1.7.5 的站点视觉：

| 素材 | 原始尺寸 | 落位 | 说明 |
| --- | --- | --- | --- |
| 樱花飘瓣（花田） | 779×1083 | **首页 Hero 主视觉** | 替换原 `hero-girl` 承担主视觉；花瓣与站点樱花主题同调 |
| 女仆（起居室） | 1374×2000 | **商品详情页氛围底纹** | 详情卡右上角 20% 透明，纯氛围不干扰阅读 |
| 星空兔耳 | 1024×1448 | **登录/注册页左侧陪衬** | 与右侧樱花小精灵形成左右呼应 |
| 花鸟拼贴 | 1024×1447 | **首页开通流程区块右侧装饰** | 14% 透明，只作暖色纹理 |
| 抱吉他 | 1600×2280 | **余额总览右侧点缀** | 替换原纯色径向光斑 |

**改动的文件**

| 类型 | 文件 | 改动 |
| --- | --- | --- |
| 样式 | `assets/css/app.css` | 新增 `.hero::before`、`.auth-page::after`、`.pd-card::after`、`.steps-section::before`、`.bal-hero::after` 五处装饰层；调整 `.hero`（`min-height` + flex 居中）、`.hero-spec`（白纱与柔光）、`.bal-hero-stats`（`margin-right` 预留）、`.pd-head`/`.pd-price-row`/`.pd-deliver-note`/`.pd-section`/`.pd-quick-buy`（`z-index` 抬升）与三档响应式断点 |
| 资源 | `assets/img/` | 新增 `hero-sakura` / `auth-cosmos` / `pd-maid` / `bal-guitar` / `bal-kotori` 共 5 组 WebP+JPEG 双份；移除已无引用的 `hero-girl`（1.7.5 横构图版） |

**技术要点**

- 5 张素材**均不带有效透明通道**（`dress.png` 虽为 RGBA 但 alpha 全 255），因此全部改用 **CSS `mask-image` 多向渐隐** 融入粉白底；需要同时应用两层遮罩时用 `mask-composite: intersect`（WebKit 用 `-webkit-mask-composite: source-in`）取交集
- Hero 与交付内容卡的横向冲突通过「右下角锚定 + 收窄宽度 + 容器增高」解决，而非降低透明度——保证人物头部完整可见
- 余额总览统计数字的可读性靠**布局预留**（`margin-right`）而非遮罩解决，因为遮罩无法阻止元素被覆盖

**升级方式**：直接覆盖代码文件（保留 `config/config.php`）即可，**无需执行任何 SQL**。静态资源版本参数走文件修改时间指纹，覆盖后浏览器缓存自动失效。

本次为纯样式与静态资源改版，无逻辑变更、无新增测试项；全量 19 套测试 **1308 项断言全部通过**。

---

### 1.7.3 → 1.7.4

**新增功能：商品购买限制次数（每人限购）**

后台可为每个商品设置「每人限购 N 次」，防止单个账号批量囤货。计数口径为**已付款订单**：

- **什么算「一次」**：已支付（含已发货）订单计入；**待支付 / 已关闭 / 已退款不计入**。因此「拍下不付款占库存」和「订单超时自动关单」都不会消耗买家名额，退款后名额自动释放
- **按商品独立**：A 商品限购不影响 B 商品；填 `0` 表示不限购（默认，完全不影响现有商品）
- **并发安全**：校验在**下单事务内、`SELECT ... FOR UPDATE` 锁住商品行之后**执行，同一商品的并发下单请求串行化。实测限购 1 次时并发 8 个请求**只成功 1 个**，无绕过
- **校验先于库存分配**：达到上限时直接拒绝，不会白占库存
- **前台提示**：商品详情页展示「每人限购 N 次」；登录后显示「还可购 N 次」，购满时按钮变灰并提示「已达购买上限」
- **后台可视化**：商品表单新增「每人限购」输入（0 ~ 9999，超出自动封顶），商品列表新增「限购」列（显示「限 N 次」或「不限购」徽章）

**数据库变更**：`ly_products` 新增 `limit_per_user`（默认 0），`ly_orders` 新增 `idx_user_product_status` 组合索引（限购统计走索引）。

覆盖代码文件（保留 `config/config.php`）后执行 `install/upgrade_1.7.4.sql`（幂等可重复执行）。升级后默认所有商品均为「不限购」，需到「商品管理 → 编辑」逐个设置。

本次共 17 套测试 1041 项断言全部通过（新增 `tests/product_limit_test.php` 60 项）。

---

### 1.7.2 → 1.7.3

**功能调整：兑换码面额下限 1.00 → 0.01**

此前 `redeem_min_amount` 的默认值为 `1.00` 且**未在后台设置页暴露**，导致无法生成 0.1 / 0.5 这类小额兑换码。本次调整：

- **下限默认值降为 `0.01`**（`install/schema.sql` 与 `Setting::redeemMinAmount()` 同步），开箱即可生成 **0.01 / 0.1 / 0.5 / 1 / 10** 等任意面额
- **升级脚本 `install/upgrade_1.7.3.sql`**：仅在当前值仍为历史默认 `1.00` 时才下调，**不会覆盖您自行设置过的值**；键缺失时补一条默认值。幂等可重复执行
- **生成弹窗默认面额修正**：原逻辑「下限为 1.00 时预填 10.00，否则预填下限值」在下限降到 0.01 后会导致表单默认填 `0.01`（易误生成一堆 1 分券），改为**恒定预填 `10.00`**
- 金额精度沿用 bcmath（`DECIMAL(10,2)` + `SCALE` 校验），0.1 + 0.5 = 0.60 无浮点误差

覆盖代码文件（保留 `config/config.php`）后执行 `install/upgrade_1.7.3.sql`。若您**此前手动改过**该配置（值不是 `1.00`），脚本会保留您的值并在输出中提示。

本次共 17 套测试 981 项断言全部通过。

---

### 1.7.1 → 1.7.2

**功能增强：优惠券被用户持有时也能删除**

此前删除优惠券遇到「用户券包里还有未使用的券」会直接拒绝，管理员无法清理发错或废弃的券。本次改为**分级删除**：

- **安全删除（默认）**：仅当券包中存在「未使用且未过期」的实例时拒绝，并明确告知张数与替代方案（可改为「停用」）；已使用、已失效的历史实例**不再阻塞**删除
- **强制删除（需口令）**：确认要清理时勾选强制删除并输入确认口令 **「永久删除」**，系统将：
  - **收回**用户手中未使用的券实例（数量在结果提示中回显）
  - 清理已过期未使用的实例与适用范围配置（`ly_coupon_scopes`）
  - 删除券模板
  - **已使用的实例保留**——这类券对应真实订单，订单自身存有 `coupon_discount` 金额快照，用户券包页显示「（券已删除）」兜底，账面数据不受影响
- **列表可视化**：券列表「持有」列显示未使用张数与订单引用数，未使用 > 0 时按钮自动切换为红色「强制删除」并出现口令输入框（按钮级确认文案由 `data-confirm` 覆盖，支持 `%n` 占位替换实际张数）
- **行为变更**：删除不存在的券由「静默成功」改为**明确返回失败**（`优惠券不存在或已被删除`），后台提示更准确

覆盖代码文件（保留 `config/config.php`）即可，**无数据库变更**。本次共 17 套测试 969 项断言全部通过。

---

### 1.7.0 → 1.7.1

**界面美化（模板/静态资源层）**

- 全站去 emoji：按钮、标题、徽章、菜单的 emoji 统一替换为**内联 SVG 线性图标**或纯文字；后台侧边栏菜单图标改为 `01-10` 数字编号
- 绿色主题重绘：`app.css` / `admin.css` 全量重写配色与组件样式（弹窗、徽章、表格、表单、卡片均保留原类名与结构）
- 首页 Hero 区右侧「宝塔面板模拟窗」改为**「交付内容」清单卡**（面板链接/账号/密码/交付时间/查看位置）；特性区图标 SVG 化；文案梳理（如「自动发货」→「自动交付」）
- 汉堡菜单按钮改为双 SVG 图标（菜单/关闭随抽屉开合由 CSS 切换，替代原 JS MutationObserver）
- 审查与回归结论：**21 个改动文件全部通过语法检查与全量回归（16 套 987 项断言 + 57 项 Web 端到端全绿）**；`e()` 转义、`url()`、CSRF、事件绑定逻辑零缺失；SVG 均为静态 path 无注入面

**升级方式**

覆盖代码文件（保留 `config/config.php`）即可，**无数据库变更**。覆盖后宝塔重载 PHP（清 OPcache）；因 CSS/JS 均带文件修改时间指纹，浏览器缓存自动失效。本次共 16 套测试 987 项断言全部通过。

---

### 1.6.1 → 1.7.0

**新增功能：商品有效期体系 + 公告改版弹窗**

**一、商品有效期（天 / 周 / 月 / 年）**

- 后台「商品管理 → 编辑/添加商品」新增**有效期**字段：数值 + 单位（天/周/月/年），**0 = 永久有效**（默认，老商品行为不变）
- 商品详情页展示「有效期：1 个月」等文案；买家支付成功后**从付款时间起算**，到期时间写入订单（`expire_at`）
- 月/年采用**日历语义**（如 1 月 31 日 +1 个月 → 3 月 3 日，闰年边界自动处理）
- 存量订单升级时按商品当前时长**自动回填**到期时间

**二、到期前 7 天自动邮件提醒**

- 扫描逻辑：已支付/已发货订单中「到期时间在 7 天内且尚未提醒」的，自动给**买家**发送提醒邮件（订单号、商品、到期日期、剩余天数、备份建议）
- 发送失败/邮件服务未配置时**不标记不吞单**，下一轮自动重试；成功发送后记录 `reminded_at`，绝不重复发送
- 触发机制：页面访问惰性触发 + `cron/tick` 计划任务兜底（每轮最多 20 封，返回 JSON 新增 `reminded` 字段）

**三、后台「到期管理」**

- 新增侧边栏「到期管理」页：**7 天内到期**（黄标剩余天数）/ **已到期待删机**（红标超期天数）/ 已删机处理 三个视图
- 控制台顶部警示条：「有 N 台机器已到期，请登录面板删除回收资源」/「有 M 台机器 7 天内到期」
- 删机是面板手动操作（系统不控制面板 API）；在面板删完后点「标记已删机」归档，订单移出待办
- 用户侧「我的订单」列表与详情同步展示「剩余 N 天 / 今天到期 / 已到期 N 天」

**四、公告改版弹窗**

- 页头横条公告改为**居中弹窗**（打开网站即弹出，支持多行内容），关闭记忆机制沿用（内容 hash，更新后自动重新弹出）

**升级方式**

覆盖代码文件（保留 `config/config.php`）后执行 `install/upgrade_1.7.0.sql`（3 个新列 + 1 个索引，全部带存在性判断可重复执行；含存量订单到期时间回填）。升级后请到「商品管理」逐一为需要限时的商品配置有效期（默认 0 = 永久，不配置不影响任何现有行为）。本次共 16 套测试 987 项断言全部通过。

---

### 1.5.5 → 1.6.0

**新增功能：订单超时自动关闭（默认 5 分钟）**

之前版本后台虽有「订单超时（分钟）」配置和 `Order::autoCloseExpired()` 方法，但**系统中没有任何调用点**，配置形同虚设。本次补全整条链路：

- **超时自动关单**：未支付订单超过设定分钟数（后台「系统设置 → 订单超时」，默认 **5 分钟**，填 0 关闭该功能）即自动关闭
- **完整关单 = 释放库存 + 退回优惠券 + 退回余额抵扣款**：修复原 `autoCloseExpired` 调用的 `close()` 不退券不退余额的问题（关单吞用户资产），改用 `closeWithRefund`
- **触发机制双保险**：
  1. **惰性触发**：首页、商品列表、支付页、用户订单列表、后台控制台被访问时自动清理
  2. **计划任务兜底**（无人访问时段）：新增 `cron/tick` 端点，宝塔「计划任务」每分钟 curl 即可；完整 URL（含自动生成的密钥）在后台设置页可直接复制
- **误关恢复兜底（防吞钱竞态）**：用户在超时边界完成支付、回调晚于关单到达时，`markPaid` 自动恢复订单——重新分配库存并发货；若库存已被抢光，按实付金额退款到用户余额并标记已退款，绝不吞钱
- **性能**：超时扫描走 `ly_orders` 现有索引 `idx_status_created`，分批处理（单次 ≤200 单）
- 新增 `tests/order_expire_test.php`（28 项）

**升级方式**

覆盖代码文件（保留 `config/config.php`）后执行 `install/upgrade_1.6.0.sql`（仅一条 UPDATE：把默认超时 30 改为 5，已自定义其他值的站点不受影响；**无表结构变更**）。可选：到后台复制计划任务 URL 配置宝塔每分钟任务。本次共 14 套测试 923 项断言全部通过。

---

### 1.5.4 → 1.5.5

**修复重大发信 Bug（这就是「换邮箱也一直失败」的根因）**

- **DATA 报文换行被炸裂**：`sendData()` 用 `str_replace(["\r\n","\r","\n"], "\r\n", ...)` 规范化换行——`str_replace` 数组参数是**串行替换**，第 2 轮把原 CRLF 中的 `\r` 单独展开、第 3 轮再把 `\n` 展开，最终**每个换行在线上变成 `\r\r\n\r\n`**。QQ SMTP 严格解析时头区被空行提前终止，报 `550 The "From" header is missing or invalid`；163 报 535 的用户也会因此踩坑。改用 `strtr` 一次性多模式替换修复
- **fwrite 部分写入**：`write()` 现循环写完整缓冲（TLS socket 大报文可能一次未写完导致报文残缺）
- 之前版本自测一直全绿的原因：mock 服务器用 `fgets` 逐行收数据，悄悄把坏换行规范化了；真实服务器（QQ/163）严格按字节解析才暴露。新增 `tests/mailer_wire_test.php`（11 项）用 **raw fread 抓线上原始字节**做全等断言，杜绝此类假象
- 症状与修复对照：QQ SMTP「AUTH 通过但 DATA 阶段 550 From header invalid」= 本 Bug，**修复后无需改任何配置即恢复**；163 SMTP「AUTH 阶段 535 authentication failed」= 账号/授权码配置问题（与本 Bug 无关），需检查账号是否填**完整邮箱地址**、授权码是否最新、SMTP 服务是否开启

**升级方式**

覆盖代码文件（保留 `config/config.php`）即可，**无数据库变更**。覆盖后宝塔重载 PHP（清 OPcache）。本次共 13 套测试 895 项断言全部通过。

---

### 1.5.3 → 1.5.4

**改进内容**

- **发信失败原因直接显示在页面上**：注册页发送邮箱验证码失败时，页面提示从笼统的「请稍后重试或联系客服」改为**显示 SMTP 真实错误原因**（SMTP 错误不含密码等敏感信息，可安全展示），无需登录服务器查日志
- **常见 SMTP 错误自动翻译成人话**：内置 163 / QQ 等国内邮箱最常见错误的人话提示，例如：
  - `535` → 授权码不正确或邮箱未开启 SMTP 服务（要用「授权码」，不是邮箱登录密码）
  - `553` → 发件人邮箱必须与登录账号完全一致
  - `554 DT:SPM` → 被反垃圾系统拒信，稍后再试或更换发件邮箱
  - `Connection timed out` → 云服务器封禁出网端口（25/465），需到安全组放行
  - `Connection refused` → 主机/端口填写错误（163：smtp.163.com + 端口 465 + SSL）
  - 其余：`521` SMTP 未开启、`530` 要求加密、`550` 拒收、`552` 容量满、`571` IP 信誉差、域名解析失败等；后台「发送测试邮件」同样受益
- 新增 `tests/` 对错误翻译的分支验证（误伤回归：端口号 `465`/`1535` 不会误命中 `535`）

**163 邮箱标准配置（后台「系统设置 → 邮件设置」）**

| 配置项 | 填写值 |
|---|---|
| SMTP 服务器 | `smtp.163.com` |
| 端口 | `465` |
| 加密方式 | **SSL** |
| 账号 | 163 邮箱完整地址（如 `xxx@163.com`） |
| 授权码 | 邮箱网页版「设置 → POP3/SMTP/IMAP」开启 SMTP 后生成的**授权码**（非登录密码） |
| 发件人邮箱 | **必须与账号一致**的 163 邮箱地址（否则报 553） |

**升级方式**

覆盖代码文件（保留 `config/config.php`）即可，**无数据库变更**。覆盖后宝塔重载 PHP（清 OPcache）。若仍发送失败，页面上显示的错误信息即为真实原因，截图即可远程定位。本次共 12 套测试断言全部通过。

---

### 1.5.2 → 1.5.3

**新增功能**

- **图形验证码（GD 库，防刷邮件）**：开启后用户点击「获取验证码」前必须先通过 4 位图形验证码，防止邮箱验证码接口被脚本批量刷取：
  - 纯 GD 实现（无第三方依赖），字符独立旋转 + 随机配色 + 干扰弧线 + 噪点，与站点深色主题融合；TTF 字体自动探测（DejaVu/Liberation/Noto/Arial），无 TTF 时自动退化为内置位图字体放大
  - 字符集去除易混淆的 `0/o/1/l/i`，**校验不区分大小写**；5 分钟有效、**一次性使用**（防重放）；Session 仅存 sha256 哈希不落明文；图片输出 `no-store` 防缓存
  - **点击图片刷新**；发送失败/成功后自动换新图
  - 后台「注册与邮箱限制」新增开关「图形验证码（防刷邮件）」，默认开启（需 PHP GD 扩展，宝塔默认安装）；挂接 1.5.0 的分组开关机制，与「注册邮箱验证码」互不干扰
- 新增 `tests/captcha_test.php`（32 项）

**升级方式**

覆盖代码文件（保留 `config/config.php`）即可，**无数据库变更**。默认自动启用图形验证码；如服务器未安装 GD 扩展（`php -m | grep gd` 检查），请在后台关闭该开关，否则无法发送邮箱验证码。本次共 12 套测试 884 项断言全部通过。

---

### 1.5.1 → 1.5.2

**Bug 修复**

- **修复「升级后手机端仍显示旧样式/旧布局」**：静态资源缓存参数此前使用 `LY_VERSION`，用户保留旧 `config/config.php` 时版本号不变（仍 `?v=1.5.0`），浏览器继续使用**缓存的旧 CSS/JS**——1.5.1 的卡片化与布局修复看似未生效。现改为**文件修改时间指纹**（`?v=文件mtime+路径crc`）：文件一被覆盖，URL 自动变化，浏览器缓存立即失效。**升级后无需清浏览器缓存、无需改 config.php**

**升级方式**

覆盖代码文件（保留 `config/config.php`）即可。若宝塔开启 OPcache 且 `opcache.validate_timestamps=0`，覆盖后请在「软件商店 → PHP → 设置 → 服务」重载一次 PHP。本次共 11 套测试 852 项断言全部通过。

---

### 1.5.0 → 1.5.1

**Bug 修复**

- **修复「注册验证码发送失败（授权码确认无误）」**：`Mailer` 此前强制 TLS 对端证书校验，宝塔/CentOS 最小安装等缺 CA 根证书包的环境下，TLS 握手在认证之前即失败——与账号密码是否正确无关。现新增**证书验证失败自动降级**：严格校验失败且判定为证书类错误时，自动以宽松校验重连重试一次并写 `smtp_tls_relaxed` 日志；重试仍失败时错误信息附上 `openssl.cafile` 配置指引
- **修复 TLS 失败根因被吞**：`error_get_last()` 只保留最后一条警告（`Unable to connect`），证书细节被覆盖。现用 `set_error_handler` 全量收集 PHP warning 提取 OpenSSL 证书错误细节（`certificate verify failed` / `unable to get local issuer certificate` 等），连接与 STARTTLS 阶段的报错均含真实根因
- **修复「后台设置了商品后无法修改配置（有的元素被遮住）」——两处布局缺陷**：
  1. **桌面后台主内容区整体被挤到首屏之外**：前台 `body{flex-direction:column}` 穿透到后台（`.admin-body` 未显式声明 direction），侧边栏占满一屏后主内容区被推到视口下方，首屏只见侧边栏、需滚动才能操作。已显式 `flex-direction:row` 修复
  2. **手机后台列表「操作」列被挤出屏幕**（编辑/库存/下架/删除按钮无法点到）：1.5.0 的表格横滑方案在窄屏下操作列藏在滑动区外。现改为**移动端表格卡片化**——`admin.js` 自动把表头列名注入单元格 `data-label`，768px 以下每行渲染为带「列名：值」的卡片，操作按钮流式排列全部可点；覆盖商品/库存/订单/用户/兑换码/优惠券/持有者全部后台列表，桌面布局不受影响

**新增**

- `tests/tls_fallback_test.php`（6 项）：内置自签名证书 mock SMTP，端到端验证「严格失败 → 自动降级 → 投递成功」，防止降级路径回归

**升级方式**

纯代码版本，**无数据库变更、无新增配置项**：备份后覆盖代码文件，保留 `config/config.php` 即可。本次共 11 套测试 852 项断言全部通过。

---

### 1.4.0 → 1.5.0

**Bug 修复**

- **修复「开启注册邮箱验证码后，邮件服务被自动关闭」**：后台「邮件设置」页签下有两个独立表单（SMTP 邮件配置 / 注册与邮箱限制），此前共用同一个保存分组，保存任一表单都会把另一个表单未提交的开关补 0。现将两组开关按表单归属拆分（`group=mail` 只管 `smtp_enabled`，`group=reg` 只管 `register_email_verify`），彻底互不影响
- 「注册与邮箱限制」卡片新增状态徽标与警示条：验证码开启但邮件服务未就绪时明确提示「验证码实际不发送」，保存时同步给出提醒

**移动端优化**

- 头部按钮收纳（次要入口进导航抽屉），抽屉改为动画展开 + 遮罩，汉堡图标随开合切换 ☰/✕；抽屉内新增用户区块（余额 / 退出登录）
- 触控目标 ≥42px、输入框 16px（防 iOS 聚焦自动放大）、`-webkit-tap-highlight` 去除点击高亮
- 支付页二维码自适应小屏，新增「手机付款：截图保存 → 扫一扫相册识别」指引
- 商品详情页价格区新增「立即购买 ↓」锚点（小屏显示），快速跳到购买盒
- 键值表（面板信息等）手机端块状化、后台页签横向滑动、列表表格横向滑动
- 全面屏安全区适配（`viewport-fit=cover` + `env(safe-area-inset-*)`）、`theme-color` 浏览器顶栏配色
- 注册页验证码发送提示由系统弹窗改为页内 toast

**升级方式**

纯代码版本，**无数据库变更、无新增配置项**：备份后覆盖代码文件，把 `config/config.php` 保留原样即可（`LY_VERSION` 兜底行为不变）。本次共 10 套测试 846 项断言全部通过。

---

### 1.2.0 → 1.3.0

**新增功能**

- 优惠券体系：满减券 / 折扣券，支持全场通用与指定商品多选、每人限领次数、发放总量封顶
- 两种领取通道：后台定向发放（多人多选 / 邮箱批量）+ 前台领券中心自主领取
- 前台「我的优惠券」页：领券中心 + 券包（可使用 / 已使用 / 已失效）+ 累计已省统计
- 商品详情页用券下拉（实时试算减免），订单详情页展示券抵扣明细
- 后台优惠券管理：列表 / 建券 / 编辑 / 定向发放 / 持券人 / 启停 / 删除

**金额链变更**

新增 `ly_orders.coupon_discount` 列，形成三段式恒等式：

```
amount = coupon_discount + balance_paid + pay_amount
```

抵扣顺序固定为**先券后余额**：券以商品原价为基数减免，余额再以「券后应付」为基数抵扣，剩余走支付宝。

**顺带修复的两个资金漏洞**

| # | 问题 | 影响 | 修复 |
|---|---|---|---|
| 1 | `markPaid()` 从不扣减 `balance_paid`（1.2.0 引入） | 部分抵扣订单即使支付宝支付成功，余额也**从未真正扣除**，等于白送余额抵扣额 | 在支付成功事务内补扣余额（带 `pay_channel !== 'balance'` 守卫避免纯余额单重复扣），并保证幂等 |
| 2 | `closeWithRefund()` 无条件下退还 `balance_paid` | 部分抵扣单**尚未支付（余额从未扣过）**时关单，会凭空给用户加钱 | 仅对 `pay_channel === 'balance'`（下单即扣款的纯余额单）退款 |

**数据库升级**

执行 `install/upgrade_1.3.0.sql` 即可，脚本幂等（可重复执行）：

```bash
mysql -uroot -p 库名 < install/upgrade_1.3.0.sql
```

改动内容：`ly_orders` 新增 `coupon_id`、`coupon_discount` 两列与 `idx_coupon` 索引；新增 `ly_coupons`、`ly_coupon_scopes`、`ly_user_coupons` 三张表；追加 `coupon_enabled`、`coupon_claim_enabled` 两项配置。**原有数据（用户 / 商品 / 订单 / 余额 / 兑换码）100% 保留。**

**新增文件**

```
app/Models/Coupon.php                        券模板模型
app/Models/UserCoupon.php                    用户券实例模型
app/Controllers/admin/CouponController.php   后台优惠券管理
app/Views/admin/coupon_list.php              后台券列表
app/Views/admin/coupon_form.php              后台建券/编辑
app/Views/admin/coupon_grant.php             后台定向发放
app/Views/admin/coupon_holders.php           后台持券人
app/Views/user/coupons.php                   前台我的优惠券
tests/coupon_test.php                        优惠券测试（252 项）
tests/coupon_delete_test.php                 优惠券删除测试（38 项）
install/upgrade_1.3.0.sql                    1.2.0 → 1.3.0 升级脚本
```

**新增路由**

| 路由 | 方法 | 说明 |
|---|---|---|
| `admin/coupon/list` | GET | 券列表 |
| `admin/coupon/form` | GET | 建券/编辑表单 |
| `admin/coupon/save` | POST | 保存 |
| `admin/coupon/toggle` | POST | 启停 |
| `admin/coupon/delete` | POST | 删除 |
| `admin/coupon/grant` | GET | 发放页 |
| `admin/coupon/doGrant` | POST | 执行发放 |
| `admin/coupon/holders` | GET | 持券人 |
| `user/coupons` | GET | 我的优惠券 |
| `user/claimCoupon` | POST | 自主领券（AJAX） |
| `user/couponQuote` | POST | 结算试算（AJAX） |

---

### 1.1.0 → 1.2.0

本版本新增：余额体系（兑换码充值 + 下单抵扣）、余额流水审计、后台兑换码生成与用户调账。

**升级步骤：**

```bash
# 1) 备份数据库与代码
mysqldump -u lycloud -p lycloud > backup_$(date +%Y%m%d).sql

# 2) 上传新代码覆盖（保留原 config/config.php！）

# 3) 执行增量升级脚本（幂等，可重复执行）
mysql -u lycloud -p lycloud < install/upgrade_1.2.0.sql
```

`upgrade_1.2.0.sql` 的行为：

- 给 `ly_users` 补 `balance` 列（`DECIMAL(10,2)`，默认 `0.00`）
- 给 `ly_orders` 补 `balance_paid` 列与相关索引
- 新建 `ly_redeem_codes`（兑换码）与 `ly_balance_logs`（余额流水）两张表
- 写入 `balance_enabled`、`redeem_enabled`、`redeem_min_amount`、`redeem_max_amount` 配置（`INSERT IGNORE`）

> ⚠️ **全新安装** 请直接用 `install/schema.sql`，**不需要**跑升级脚本。

---

### 1.0.0 → 1.1.0

本版本新增：SMTP 邮件服务、注册邮箱域名白名单、邮箱验证码注册、找回密码、发货邮件通知。

**升级步骤：**

```bash
# 1) 备份数据库与代码
mysqldump -u lycloud -p lycloud > backup_$(date +%Y%m%d).sql

# 2) 上传新代码覆盖（保留原 config/config.php！）

# 3) 执行增量升级脚本（幂等，可重复执行）
mysql -u lycloud -p lycloud < install/upgrade_1.1.0.sql

# 4) 进入后台 → 系统设置 → 📧 邮件设置，填写 SMTP 并测试发信
```

`upgrade_1.1.0.sql` 是**幂等**的，它的行为：

- 给 `ly_users` 补 `email_verified`、`email_verified_at` 两列（已存在则跳过）
- 新建 `ly_email_codes`、`ly_password_resets` 两张表（`CREATE TABLE IF NOT EXISTS`）
- 写入 11 条新增配置项（`INSERT IGNORE`，**不会覆盖你已有的站点名等配置**）

> ⚠️ **全新安装** 请直接用 `install/schema.sql`（已包含全部新表与新配置），**不需要**再跑升级脚本。

**升级后的行为变化：**

1. 数据库里**存量用户**的 `email_verified` 为 `0`。这不影响他们登录，只是记录状态。
2. 填写并开启 SMTP 后，**注册页会自动出现验证码输入框**；未配置则自动隐藏，注册流程不受影响。
3. 找回密码入口显示在登录页「忘记密码？」。
4. 支付成功自动发货后，若 SMTP 已配置，会把面板信息同时发到用户邮箱。

---

## 附：本地 / 沙箱快速启动

项目根目录提供 `start.sh`，用于在单机上拉起 MySQL + PHP-FPM + Nginx：

```bash
bash start.sh
# 访问 http://127.0.0.1:8080
```

脚本会依次检查并启动三个服务，输出访问地址。适合开发调试与演示，**生产环境请使用 systemd 管理各服务**。

---

**许可**：本项目为交付源码，可自由修改用于商业用途。

---

## 15. MNBT 主机自动开通（1.7.6）

### 15.1 这是什么

MNBT（**梦奈宝塔主机系统**）是一套第三方主机销售/管理系统，提供 HTTP API 用于开通、续费、删除主机。

1.7.6 让本站商品支持**第三种交付方式**：支付成功后**实时调用 MNBT 接口开通主机**，取代过去必须提前把主机信息录入库存池的做法。

| 交付方式 | 适用场景 | 交付物来源 |
| --- | --- | --- |
| `1` 库存池卡密（默认） | 已有现成主机、手工录入 | 后台「库存管理」预录的卡密池 |
| `2` **MNBT 实时开通** | 主机按需现开 | 支付回调后调用 MNBT API 现开 |
| `3` 人工发货 | 需人工介入的场景 | 管理员后台手动分配 |

三者与既有的「库存模式」（限定/不限库存）、「自动发货」（自动/人工）两个字段**正交共存**，互不干扰。升级后存量商品一律回填为 `1`，**行为不变**。

### 15.2 配置步骤

**第一步：拿到 MNBT 的 4 个参数**

登录你的 MNBT 后台：

| 参数 | 在 MNBT 里的位置 | 说明 |
| --- | --- | --- |
| 接口地址 | 你的 MNBT 站点根地址 | 如 `https://mn.example.com`，**不要**带 `/api/api.php`（系统会自动裁掉） |
| 宝塔编号 `mn_bh` | 宝塔列表 | 哪台服务器负责开通 |
| API 密钥 `mn_key` | 系统设置 → API 设置 | 全局密钥 |
| 宝塔调用密钥 `mn_keye` | 宝塔列表 | 该宝塔的调用密钥 |
| 插件版本 `mn_vs` | 插件设置 | `16` = v1.6，`17` = v1.7 |

**第二步：后台填配置**

**后台 → 系统设置 → MNBT对接**，填入上述参数，开启开关，点「**测试连接**」。
测试通过后 Tab 标题会显示 ✓ 标记。

> 密钥字段留空表示「不修改」，不会把已有密钥清掉。

**第三步：新建/编辑商品**

**后台 → 商品管理 → 新增商品**，把「交付方式」选为「**MNBT 实时开通**」，然后填写规格：

| 商品字段 | 对应 MNBT 参数 | 说明 |
| --- | --- | --- |
| 主机账号前缀 | — | 最终账号 = `前缀 + 订单号后缀`，如 `ly20261003A1B2` |
| 网页空间 (MB) | `webdx` | **必填**，如 `1024` |
| 数据库空间 (MB) | `sqldx` | 如 `200` |
| 月流量 (GB) | `sizemax` | 如 `50` |
| 最多绑定域名 | `ymbds` | 如 `5` |
| 产品类型 | `type` | `主机` 或 `CDN` |

> 交付方式选 MNBT 时，未填「网页空间」或 MNBT 接口未配置完整，保存会被拒绝并给出提示。

### 15.3 开通流程与时序

```
用户下单 ─→ 不占库存池（MNBT 商品直接跳过库存分配）
   │
支付成功 ─→ markPaid() 事务内：deliver_status = PENDING(1)
   │
   └─ 事务提交后（关键）─→ MnbtClient::createHost() 调 MNBT 开通
                              │
                     ┌────────┴────────┐
                     ▼                 ▼
                  成功               失败
                     │                 │
        写 ly_stocks(source=2)    tries < max_tries ?
        绑定订单、订单置已发货         │
        deliver_status = DONE(2)  ┌───┴───┐
                                  ▼       ▼
                                是        否
                                  │       │
                        RETRY(3) 待重试  FAILED(4) + 自动退款到余额
                                  │
                    重试来源：cron 每次 tick / 用户访问触发 / 后台手动
```

**为什么网络调用必须放在事务外**：MNBT 开通接口最长可能耗 15 秒，而支付宝异步通知约 25 秒即判定超时并重试。若在持有 `ly_orders` 行锁的事务里等待，会长时间锁行并拖慢整个支付回调。因此设计为**事务内只标记状态，事务提交后才由 `flushAfterCommit()` 排空队列执行真正的开通**。

### 15.4 交付状态说明

订单详情页与后台订单列表都会展示开通状态：

| `deliver_status` | 含义 | 前台表现 | 后台表现 |
| --- | --- | --- | --- |
| `0` | 无需自动开通 / 未开始 | — | — |
| `1` | **开通中** | 「主机开通中」+ 每 5s 自动轮询刷新 | 提示等待 |
| `2` | **已开通** | 一键登录按钮 + 主机账号密码 + FTP 信息 | 可「代用户登录面板」 |
| `3` | **待重试** | 「主机开通中（已尝试 N 次）」 | 「手动重试开通」按钮 |
| `4` | **开通失败** | 「开通失败，已退款」+ 具体原因 | 红色警示，引导用户重新下单 |

### 15.5 一键登录的安全设计

MNBT 的一键登录直链形如：

```
{站点}/user/idcdl.php?gn=logine&username=x&password=y
```

**密码是明文 query 参数**。如果直接把它写进页面，会残留在浏览器历史、页面源码（Ctrl+U）、截图/录屏、以及第三方脚本可读的 DOM 里。

因此本站改为**服务端中转**：页面上只出现 `index.php?r=order/mnbtlogin&id=N`（不含密码），密码仅在「点击 → 服务端 302 → MNBT」这一跳中短暂存在于 `Location` 头，并附带 `Cache-Control: no-store` 与 `Referrer-Policy: no-referrer`。

同时做了三重门禁，任一不满足即拒绝：

1. **归属校验**：前台必须是本人订单（管理员可跨用户，供客服排障）
2. **状态校验**：必须 `deliver_status = DONE` 且订单已支付
3. **配置校验**：MNBT 接口配置完整

### 15.6 失败重试与退款策略

管理员可在「系统设置 → MNBT对接」配置「**开通失败重试次数**」（默认 `2`，范围 `0~10`）：

| 配置值 | 行为 |
| --- | --- |
| `0` | **失败即退款**到余额（最保守） |
| `N`（默认 2） | 失败先标记「待重试」，最多尝试 `N + 1` 次（含首次），**全部失败才退款** |

**为什么要有「待重试」这一层**：MNBT 没有查询主机是否存在的接口，也没有幂等键。如果上游网络抖动导致超时，而我们立刻判定失败并退款，就可能出现「**主机其实已经开出、钱又退给了用户**」的资损。加一层待重试，给上游一个恢复窗口，能显著降低这类误判。

重试触发点有三个（互为兜底）：

- **Cron**：`index.php?r=cron/tick` 每次执行时扫描待重试订单（建议每分钟一次）
- **用户访问**：任何人访问站点时惰性触发一次（`order_auto_close()` 内顺带扫描）
- **后台手动**：订单详情页「手动重试开通」按钮

**人工重试的特殊规则**：不受重试次数限制（管理员已确认上游恢复），且**失败时不会自动退款**，而是回落到「待重试」——避免管理员排障过程中把用户的钱静默退掉、订单关掉。

**已退款订单不可重试**：`deliver_status = FAILED` 的订单会被拒绝重试，防止重复扣款；应引导用户重新下单。

### 15.7 账号与密码生成

| 项目 | 规则 |
| --- | --- |
| 主机账号 | `前缀 + 订单号字母数字段`。前缀清洗为 `[A-Za-z0-9_]` 且**首字符强制为字母**（MNBT 侧更稳妥）；空则回落系统默认前缀 `ly`。后缀源自全局唯一的订单号，再对 `ly_orders.mnbt_username` 做存在性校验兜底（冲突则追加 3 位随机十六进制，最多重试 5 次） |
| 主机密码 | 12 位随机，字符集 `abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789`——**剔除易混淆的 `0 O 1 l I`**，用 `random_int()` 生成 |
| FTP 账号/密码 | MNBT 设计上**与主机登录账号密码同源**，订单详情页已一并展示 |

账号在**发起开通请求之前**就落库到 `ly_orders.mnbt_username`，这样重试时能沿用同一个账号，保证幂等。

### 15.8 幂等保障（三重）

因为 MNBT 没有幂等键，重复开通会造成真实资损（供应商额度被吃掉），所以做了三层防护：

1. `reopenMnbtDelivery()` 入口仅处理 `deliver_status ∈ {PENDING, RETRY}` 的订单，`DONE` 直接返回
2. `markMnbtDelivered()` 内用 `FOR UPDATE` 二次确认 `deliver_status !== DONE` 才写面板记录
3. `refundMnbtFailure()` 内检查若已是 `FAILED` 则直接 `return`，杜绝重复退款

再加上 `flushAfterCommit()` 里对订单号做 `array_unique()`，同一订单在一次请求内不会被重复排入开通队列。

### 15.9 常见问题

**Q：测试连接失败，提示「缺少必带参数」？**
A：说明 `mn_bh` / `mn_key` / `mn_keye` / `mn_vs` 有缺。回到 MNBT 后台核对，注意别把「宝塔调用密钥」和「API 密钥」搞混。

**Q：提示「插件版本与MNBT版本不匹配」（code=300）？**
A：MNBT 侧插件与主程序版本不一致。到 MNBT 后台更新插件，或把本站的「插件版本」改成与之匹配的值（`16` / `17`）。

**Q：订单一直停在「开通中」？**
A：先看后台订单详情的「最近失败原因」。如果是网络类错误，等自动重试或点「手动重试开通」；若 MNBT 接口未配置，页面会给出跳转设置的链接。

**Q：用户收到「开通失败，已退款」，但 MNBT 后台其实有这台主机？**
A：说明上游抖动导致误判。请到 MNBT 手动删掉该主机（账号即订单的「主机账号」），再引导用户重新下单。如果这类情况频繁出现，把「开通失败重试次数」调大。

**Q：能不能既卖 MNBT 现开主机，又卖预录卡密？**
A：可以。交付方式是**按商品**配置的，不同商品可以选不同方式，同时存在互不影响。
