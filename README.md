# XRMPAY PHP 版本

这是 XRMPAY 的 PHP 行为兼容版本。源码只放在本目录；服务器运行态、数据库配置和 Lua 插件继续留在服务器，不复制进本地源码和发布包。

## 当前边界

- 前端 UI：使用本地 `public` 静态资源，目标是页面行为和接口契约一致。
- 后端：PHP 8.1 + PDO；本地使用 SQLite，生产使用环境变量配置的 MySQL。
- 兼容入口：`/api`、`/xpay`、`/pay`、`/cashier`、`/admin`。
- 运行态：通过 `XARR_RUNTIME_DIR` 指向服务器运行目录。
- 插件：通过 `XARR_PLUGIN_DIR` 指向固定插件根目录；支付插件使用 `plugins/pay/<插件名>/manifest.json` 独立目录装配。
- 数据库：通过环境变量提供连接参数，不把服务器密码写入源码或文档。

## 本地初始化

```powershell
php scripts/init-local.php
.\start-local.ps1 -Port 8088
```

`scripts/init-local.php` 只创建全新的空 SQLite schema：数据库文件已存在时直接失败，不执行旧库迁移、不覆盖文件、不创建管理员、商户、订单或演示支付数据。

本地验收需要测试数据时，必须显式使用隔离测试库：

```powershell
$env:XARR_DB_DSN = "sqlite:$PWD\var\test-<32位小写GUID>.sqlite"
$env:XARR_ENV = "test"
$env:XARR_FIXTURES = "1"
php scripts/init-local.php
php scripts/init-local-fixtures.php
.\start-local.ps1 -Port 8088
```

测试夹具账号为 `admin / 123456`、`coco / 123456`，只允许写入 `var/test-<guid>.sqlite`，不允许写入现有本地库。

首页直接使用新版本原程序自带的 `templates/index/default` 前端模板构建产物，位于 `public/index/`；未使用旧版本模板或 PHP 自建首页。

## 本地检查

```powershell
php -l public/index.php
php -l public/router.php
php -l src/Application.php
php -l src/Support/Config.php
php -l src/Support/Database.php
php -l src/Support/Response.php
php -l src/Support/EpaySigner.php
php -l src/Http/Request.php
php -l src/Http/Router.php
php -l src/Http/Controllers/HealthController.php
php -l src/Http/Controllers/EpayController.php
php -l src/Http/Controllers/PayController.php
php tests/EpaySignerTest.php
php tests/OrderNotifierTest.php
php tests/PaymentFlowTest.php
php tests/PaymentPluginContractTest.php
php tests/PluginRuntimeTest.php
php tests/PluginHttpTest.php
php tests/RebateCardRechargeTest.php
php tests/MfaTest.php
php tests/MfaHttpTest.php
php tests/AdminFeatureCatalogTest.php
php scripts/smoke-test.php http://127.0.0.1:8088
python scripts/browser-login-test.py http://127.0.0.1:8088
python scripts/browser-admin-test.py http://127.0.0.1:8088 --port 9234
python scripts/browser-admin-menu-test.py http://127.0.0.1:8088 9235
python scripts/browser-merchant-menu-test.py http://127.0.0.1:8088 9243
```

## 本地启动

```powershell
.\start-local.ps1 -Port 8088
```

访问 `http://127.0.0.1:8088/api/system/timestamp`。商户入口是 `/login`，后台入口是 `/admin/login`，支付页、收银台和安装页均保留为静态 UI 路由。

异步通知 worker：

```powershell
php scripts/notify-worker.php --once --limit=100
php scripts/notify-worker.php --limit=100 --sleep=2
```

支付插件运行 worker：

```powershell
php scripts/plugin-worker.php --once --limit=100
php scripts/plugin-worker.php --limit=100 --sleep=6
php scripts/plugin-worker.php --once --plugin=yun_qnm_lz --account-id=1
```

插件 worker 只执行插件清单中明确声明的 `heartbeat`、`cron` 能力。真实网关、账号登录态或流水接口未配置时会记录中文失败原因，不会创建假流水或假支付成功。流水按插件、收款账号和第三方流水号唯一入库，再按收款账号、商户归属、金额和时间窗口匹配待支付订单，结算复用统一订单事务。

支付回调只负责结算并写入 `notify_queue`，不会在 HTTP 请求内访问商户通知地址。worker 使用至少一次投递语义，商户端必须按 `trade_no` 或 `out_trade_no` 幂等处理。失败任务按 30 秒、60 秒、120 秒递增退避，默认最多尝试 5 次；可用 `XARR_NOTIFY_MAX_ATTEMPTS` 调整。管理员队列接口为 `GET /api/admin/notify/queue` 和 `POST /api/admin/notify/queue/retry`。

## 已实现

- 健康检查和系统时间接口
- Epay MD5 签名、商户查询、单订单查询、批量订单查询
- Epay `mapi.php`、`submit.php`、`notify.php`、`api.php` 兼容入口
- 订单异步通知持久化队列、CLI worker、指数退避和管理员重试
- 管理员与商户登录、验证码、各自 profile
- 后台支付类型、通道、通道账号、订单、统计和插件只读接口
- 后台使用规则确认：`/api/admin/option/compliance`
- 后台存储孤立文件扫描和清理：只扫描 `public/uploads`，保护已登记文件并拒绝目录外路径
- 后台模板目录、通知通道/事件矩阵、配置向导检查和短信插件目录扫描；没有真实短信插件时返回空目录，不伪造可发送服务商
- 支付页订单详情、状态轮询、支付方式选择、语音状态、二维码/文本收款信息
- 收银台密钥解析和按分创建订单
- 支付插件注册器、静态收款码插件、通道/账号严格分配、轮询优先级、金额上下限和日限额
- 目录化支付插件：每个插件独立包含 `manifest.json`、`adapter.php` 和 `form.php`
- 支付插件运行态：统一 `RuntimePlugin` 契约、心跳、定时流水、第三方流水去重、金额/商户/账号校验、自动结算、运行日志和后台只读状态
- `pay_codes`、`pay_account_ext`、`pay_polling`、`pay_polling_account` 支付扩展表
- 充值卡密核销、套餐兑换、余额流水和重复核销保护
- 邀请返佣账本、订单/充值返佣、返佣明细和佣金划转并发保护
- 在线充值订单：易支付模式和系统默认收款商户模式；配置不完整时明确拒绝创建订单
- 商户 MFA/TOTP 动态口令：绑定二维码、绑定校验、登录二次验证、一次性安全票据、解绑和敏感操作 step-up 校验
- 商户邮箱/手机验证码绑定：哈希保存、过期、错误次数、重发限制和发送失败作废
- 商户 Passkey/WebAuthn：注册、登录、重命名、删除、签名计数器和 step-up 校验
- 商户 RSA2 密钥：私钥 AES-256-GCM 加密保存，生成时仅返回一次，查看/重置要求 step-up
- APP 绑定票据与登录票据严格区分，OAuth 未配置时拒绝客户端伪造身份
- SQLite 本地 schema、显式测试夹具和独立启动脚本
- 安装引导接口：授权码本地保存、空库校验、schema 导入、管理员创建、重复安装拒绝和 `.env` 写入
- 安装态锁定：未安装全站跳转 `/install`、安装完成写只读锁、锁定后禁止重复进入安装页

未配置的外部能力不会返回空成功或固定待处理状态：邮件/短信真实发送、OAuth、在线应用商店、在线版本检查、共享通道和非 MFA 场景的外部认证票据仍返回明确的 `501` 未实现/未配置错误。Passkey/WebAuthn 和商户 RSA2 安全逻辑已实现，但必须正确配置站点域名、OpenSSL 和密钥环境变量。在线充值只有在真实收款配置完整时才创建订单。

## 版本管理

项目提交到 `https://github.com/hzgz/xarrpay.git`。每次完成可运行交付后执行一次 commit，并创建新 tag，方便回退。

提交前必须确认 `.gitignore` 排除了 `.env`、`var/`、SQLite、日志、PID、`reverse-work/`、服务器运行态、真实密钥和私有插件正文。

本轮版本标记：`xarr-php-ys-20260927-full-audit-r30-final`。

## 生产部署配置

不要把真实值写进文件，使用服务器环境变量或 aaPanel PHP-FPM 配置：

```text
APP_ENV=production
APP_DEBUG=0
XARR_SITE_URL=https://ys.973700.xyz
XARR_WEBAUTHN_RP_ID=ys.973700.xyz
XARR_WEBAUTHN_ORIGIN=https://ys.973700.xyz
XARR_RUNTIME_DIR=/www/wwwroot/xarr-php-ys
XARR_PLUGIN_DIR=/www/wwwroot/xarr-php-ys/plugins
XARR_DB_HOST=127.0.0.1
XARR_DB_PORT=3306
XARR_DB_NAME=xarrpay
XARR_DB_USER=xarrpay
XARR_DB_PASSWORD=<server-secret>
XARR_DB_CHARSET=utf8mb4
XARR_NOTIFY_MAX_ATTEMPTS=5
XARR_MAIL_FROM=<configured-mail-from-or-empty>
XARR_SMS_WEBHOOK_URL=<configured-sms-webhook-or-empty>
XARR_RSA_ENCRYPTION_KEY=<independent-high-entropy-secret>
XARR_PLATFORM_RSA_PUBLIC_KEY=<platform-public-key-pem>
```

MySQL 生产库首次启用或升级时，必须先备份并按部署记录核对、执行对应 schema 或迁移脚本。SQLite 本地库由 `scripts/init-local.php` 创建全新 schema；已有数据库不会自动升级或覆盖。

## 一键安装

访问 `/install` 使用安装引导。安装器只接受全新空库：目标库已有表或已有管理员时会直接拒绝，不执行清库、迁移或覆盖。

安装流程写入 `.env` 和 `var/install-state.json`，并按数据库类型导入 `database/schema.mysql.sql` 或 `database/schema.sqlite.sql`。初始化只写必要系统选项和支付类型，不创建演示商户、订单或收款账号。当前 PHP 版本后台入口固定为 `/admin`，安装页的后台路径必须填写 `admin`。

安装成功后会在 `var/install.lock` 写入只读安装锁。没有该锁时，首页、后台、商户中心、支付页、动态接口和未知路径全部跳转到 `/install`；只有安装页资源和 `/api/install/*` 安装接口放行。锁存在后重新访问 `/install` 会跳回首页，安装接口也不会覆盖已完成安装。

本工程当前不包含生产数据库、真实商户密钥、私有 Lua 插件正文和第三方支付真实扣款动作。PHP 运行态只执行独立插件目录中的明确契约；生产部署前必须先核对真实数据库字段、PHP-FPM 权限、插件目录读取权限、真实流水接口和 Nginx 独立站点配置。

## 记录入口

- 本次逐项修改：`docs/03_本次修改记录_20260922.md`
- 接口完成清单：`docs/04_接口完成清单.md`
- 商户安全、密钥、Passkey 与扫码登录：`docs/13_商户安全密钥Passkey与扫码登录实现记录_20260926.md`
- 一键安装逻辑：`docs/14_一键安装实现记录_20260926.md`
- 线上 r16 重装与安装验收：`docs/15_线上r16重装安装验收记录_20260926.md`
- 部署教程：`docs/部署教程.md`
- 运行态和插件分离：`docs/02_服务器运行态与插件分离.md`
- 支付插件目录化重构：`docs/16_支付插件目录化重构记录_20260926.md`
- 支付插件运行态与自动结算：`docs/19_支付插件运行态与自动结算实现记录_20260927.md`
- 线上 r20 插件运行态同步验收：`docs/20_线上r20插件运行态同步验收记录_20260927.md`
- 线上 r20 清空重装与 admin/123456 验收：`docs/21_线上r20清空重装admin-123456验收记录_20260927.md`
- 目录与文件说明：`docs/05_目录与文件说明.md`
- MFA/TOTP 动态口令：`docs/10_MFA动态口令实现记录_20260926.md`
- 后台存储孤立文件：`docs/11_后台存储孤立文件实现记录_20260926.md`
- 后台目录与配置向导：`docs/12_后台目录与配置向导实现记录_20260926.md`
- 全量错误排查与二维码解析修复：`docs/29_全量错误排查与修复记录_20260927.md`
- 继续全量排查、二维码掩码修复与线上复测：`docs/30_继续全量排查与线上复测记录_20260927.md`
