# Agent Note: 首次安装的一次性安装令牌（INSTALL_TOKEN）

Status: implemented

## Problem

安装器唯一的门禁是 `config/Db.php` 是否存在。完成安装前 `POST /install/install` 对任意直连客户端开放：`isAjax(true)` + `is_cross_origin_request()` 只防跨站伪造（浏览器 anti-CSRF），curl 加上 `X-Requested-With` 与同源 `Origin` 即可绕过。公网上的攻击者只要在运维完成安装向导之前到达 `/install`，就能创建 `power=6` 的管理员、写入 `config/Db.php` 永久锁死真实运维（"程序已经安装"），并接管面板上所有第三方平台 cookie。

## Decision

部署链路生成一次性安装令牌，安装器在配置了令牌时强制校验：

- `docker/deploy.sh` 用 `set_if_placeholder INSTALL_TOKEN '' "$(random_hex 24)"` 写入 `.env` 并在部署输出中明文展示（令牌经部署日志这条带外通道交给运维，绝不回显到页面）。
- `compose.yaml` 把 `INSTALL_TOKEN` 显式传入 app 容器（基础镜像 serversideup/php 默认 `clear_env=no`，PHP 可直接 `getenv`，与 `CRON_KEY`/`MYSQL_*` 同机制）。
- 安装器 `install()` 在 AJAX/同源校验后用 `hash_equals` 校验 `install-token` 字段；未配置令牌时行为与从前完全一致（手工安装、存量部署不受影响）。
- 安装页仅在 `$install_token_required` 为真时渲染令牌输入框，页面不泄露期望值（`tests/InstallDatabaseConfigTest.php` 钉死渲染形态、拒绝响应与"配置为空时不要求令牌"）。

同时修正安装器重跑死锁：安装器自己的提示让运维删除 `config/Db.php` 重装，但对已有数据的库重跑 `install.sql` 会在第一条 `CREATE TABLE` 上以 1050 失败并得到误导性的"请检查连接信息"。安装器现在在导入 schema 前用 `SHOW TABLES LIKE 'cloud\_%'` 预检，库非空时明确拒绝并提示清空或换库。

## Alternatives considered

**只加每 IP 速率限制。** 输了：不阻断攻击，只拖慢；窗口由部署节奏决定，限流无法关闭它。

**自动从环境变量创建管理员。** 输了：管理员凭据进 `.env`/compose 配置属于秘密落盘扩散，且改变了"首次访问向导建号"的既定部署体验（`InstallDatabaseConfigTest` 钉死了请求创建管理员的行为）。

**要求运维向容器内写文件以证明服务器控制权。** 输了：对 compose 部署意味着 `docker compose exec`，体验差于读一次部署输出。

**删除 360webscan 白名单或 WAF 本身。** 输了：白名单按路径锚定的方案已由 2026-10-11-untrusted-input-boundaries 决策记录钉死，与本问题无关。

## Consequences

存量部署升级后 `INSTALL_TOKEN` 为空，安装器保持旧语义（它们早已安装，入口已被 `config/Db.php` 锁死）；重新执行 `deploy.sh` 的存量部署会生成令牌并在输出中展示——无害，仅多一行输出。新部署的安全窗口从"部署完成到运维完成向导"收缩为"拿到令牌的人才能安装"。若运维遗失令牌，可在 `.env` 中置空 `INSTALL_TOKEN` 重启容器（自担窗口风险）或填入自选值。
