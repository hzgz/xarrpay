# XArrPay PHP 重构版

这是 XArrPay 的 PHP 行为兼容重构工程。源码只放在本目录；服务器运行态、数据库配置和 Lua 插件继续留在服务器，不复制进本地源码和发布包。

## 当前边界

- 前端 UI：使用本地 `public` 静态资源，目标是页面行为和接口契约一致。
- 后端：PHP 8.1 + PDO；本地使用 SQLite，生产使用环境变量配置的 MySQL。
- 兼容入口：`/api`、`/xpay`、`/pay`、`/cashier`、`/admin`。
- 运行态：通过 `XARR_RUNTIME_DIR` 指向服务器运行目录。
- 插件：通过 `XARR_PLUGIN_DIR` 指向服务器插件目录；本地不保存私有插件正文。
- 数据库：通过环境变量提供连接参数，不把服务器密码写入源码或文档。

## 本地初始化

```powershell
php scripts/init-local.php
.\start-local.ps1 -Port 8088
```

本地演示数据只用于验收，不是生产配置：管理员 `admin / 123456`，默认商户 `coco / 123456`，订单 `LOCAL-DEMO-0001`，收银台密钥 `local-demo-key`。

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
php scripts/smoke-test.php http://127.0.0.1:8088
python scripts/browser-login-test.py http://127.0.0.1:8088
```

## 本地启动

```powershell
.\start-local.ps1 -Port 8088
```

访问 `http://127.0.0.1:8088/api/system/timestamp`。商户入口是 `/login`，后台入口是 `/admin/login`，支付页、收银台和安装页均保留为静态 UI 路由。

## 已实现

- 健康检查和系统时间接口
- Epay MD5 签名、商户查询、单订单查询、批量订单查询
- Epay `mapi.php`、`submit.php`、`notify.php`、`api.php` 兼容入口
- 管理员与商户登录、验证码、各自 profile
- 后台支付类型、通道、通道账号、订单、统计和插件只读接口
- 后台使用规则确认：`/api/admin/option/compliance`
- 支付页订单详情、状态轮询、支付方式选择、语音状态、二维码/文本收款信息
- 收银台密钥解析和按分创建订单
- SQLite 本地 schema、演示数据和独立启动脚本

## 版本管理

项目提交到 `https://github.com/hzgz/xarrpay.git`。每次完成可运行交付后执行一次 commit，并创建新 tag，方便回退。

提交前必须确认 `.gitignore` 排除了 `.env`、`var/`、SQLite、日志、PID、`reverse-work/`、服务器运行态、真实密钥和私有插件正文。

本轮建议版本标记：`v1.0.1-merchant-login-fix`。

## 生产部署配置

不要把真实值写进文件，使用服务器环境变量或 aaPanel PHP-FPM 配置：

```text
XARR_RUNTIME_DIR=/www/wwwroot/xarr
XARR_PLUGIN_DIR=/www/wwwroot/xarr/plugins
XARR_DB_HOST=127.0.0.1
XARR_DB_PORT=3306
XARR_DB_NAME=xarrpay
XARR_DB_USER=xarrpay
XARR_DB_PASSWORD=<server-secret>
XARR_DB_CHARSET=utf8mb4
```

本工程当前不包含生产数据库、真实商户密钥、私有 Lua 插件正文和第三方支付真实扣款动作。生产部署前必须先核对真实数据库字段、PHP-FPM 权限、插件目录读取权限和 Nginx 独立站点配置。

## 记录入口

- 本次逐项修改：`docs/03_本次修改记录_20260922.md`
- 接口完成清单：`docs/04_接口完成清单.md`
- 部署教程：`docs/部署教程.md`
- 运行态和插件分离：`docs/02_服务器运行态与插件分离.md`
- 目录与文件说明：`docs/05_目录与文件说明.md`
