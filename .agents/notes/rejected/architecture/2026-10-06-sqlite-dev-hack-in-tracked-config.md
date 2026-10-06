# Agent Note: 本地 SQLite 调试不改跟踪的 config/database.php

Status: rejected

被否决的做法：在未跟踪的 `config/Db.php` 里声明 `type=sqlite`，同时把跟踪的 `config/database.php` 改成 `$database = [...]` 再追加一段——运行时用 `\think\facade\Config::get('Db')` 判断类型，把默认连接切到 `connections.sqlite` 指向 `runtime/loopdeck-local.sqlite`。

## Problem

本地想不开 MySQL 就跑面板，并让 `php tests/*Test.php` 之外的真实页面可用。该补丁确实生效（`Config::parse()` 把键转小写、`glob()` 让 `Db.php` 先于 `database.php` 载入），但它把一次性开发技巧写进了生产核心配置。

## Decision

撤销跟踪文件里的这段逻辑，`config/database.php` 恢复为纯 MySQL 默认连接；本地 SQLite 只保留在测试夹具里（`tests/FunctionalDatabaseBootstrap.php` 自建 `:memory:` 的 `fixture` 连接）。实测证据：撤掉补丁后 55 个 PHP 回归与 Node 前端交互用例全部通过，说明该补丁并非测试所需，只是开发机便利。此前的本地补丁备份在 `runtime/database.php.sqlite-patch.bak`（`runtime/` 已被忽略），开发者需要时可在本地工作区自行恢复，但不进入提交。

## Alternatives considered

- **改成 env 驱动的 `connections.sqlite`**：`database.default` 已读 `env('database.driver')`，加一段通用 sqlite 连接看似干净，但它让「应用可以整站跑在 SQLite」变成官方支持的错觉，而真实 SQL 与锁语义并不支持（见 Consequences）。
- **另存一个未跟踪的 `config/*.php` 覆盖文件**：ThinkPHP 按文件名建键，同名 `database` 无法共存，等于换个地方写同一个 hack。
- **保留补丁并补文档**：Docker 侧不接受——`Dockerfile:59,92` 与 `docker/entrypoint.sh:14,30` 每次启动都把镜像内 config 覆盖回持久卷，跟踪文件里的分支会随发布进入生产实例。

## Consequences

- 面板自身 SQL 是 MySQL 专有的：`ON DUPLICATE KEY UPDATE`/`INSERT IGNORE`（`app/service/NotificationRepository.php`、`app/admin/controller/Ajax.php`、安装器）与 `MOD(CRC32(...))` 分片（`app/cron/controller/Task.php`）在 SQLite 下直接抛错，`install.sql` 也无法回放。
- 更危险的是静默降级：think-orm 的 `Sqlite::parseLock()` 返回空串，`->lock(true)` 编译成普通 SELECT，兑换码与建号依赖的行锁串行化在本地根本不会被执行，回归只能靠 `tests/SharedAccountLimitTest.php` 这类隔离夹具。
- 本地跑整站需要 MySQL：按 AGENTS.md 用 `config/Db.example.php` → `config/Db.php`，或 `sh docker/deploy.sh` 自带 MySQL 服务。跟踪配置不再为单人开发场景增加分支。
