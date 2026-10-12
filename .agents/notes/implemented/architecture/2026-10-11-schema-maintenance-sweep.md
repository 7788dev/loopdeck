# Agent Note: 旧安装的结构迁移入口（SchemaMaintenance 每日清扫）

Status: implemented

## Problem

`install.sql` 只在首次安装执行；此后的索引与引擎变更永远到不了只走镜像自动更新的存量部署。实际缺口有两类：

- `udx_users_username` 缺失时，`Users::reg` 用来兜底并发重名的 `PDOException` 捕获静默失效，重复用户名可以被创建；`udx_accounts_identity`/`udx_jobs_identity`/`idx_jobs_due`/`idx_task_logs_retention` 缺失则退化了调度与清理查询。
- `cloud_notice` 长期保持遗留的 MyISAM/utf8 定义，MySQL 8 严格模式下标题/正文里任何 4 字节字符（emoji）都会让 `Notice::add` 的 INSERT 直接失败。

全仓库此前唯一的运行时结构迁移是 `NotificationRepository::ensureSchema()`（三张通知表的 `CREATE TABLE IF NOT EXISTS`）。

## Decision

新增 `app\service\SchemaMaintenance`，由统一调度器（`Task::index`）每日最多执行一次（与其他维护清扫相同的 stamp + flock 门）：

- `plan()` 是纯函数：输入各表已存在的索引名列表与表状态，输出精确的 `ALTER` DDL（列集与 `install.sql` 完全一致）。离线套件无需 MySQL 即可钉死每条语句（`tests/SchemaMaintenanceTest.php`）。
- 元数据不可得的表（`SHOW INDEX`/`SHOW TABLE STATUS` 失败，例如离线 SQLite fixture 或数据库不可用）按 null 处理并跳过——绝不猜测；`cloud_notice` 仅在引擎非 InnoDB 或字符集非 utf8mb4 时转换。
- 整个清扫 best-effort：任何异常吞掉且不写 stamp，下个调度周期重试；不破坏调度本身。
- 验证码表的 `send` 和 `ip` 分别至少为 `varchar(255)`、`varchar(45)`，覆盖允许保存的邮箱及 IPv6。`plan()` 接收列类型，仅读取已知且过短的 varchar 列并扩容，不缩减管理员扩展的列。`schema-maintenance-v2.stamp` 使新增列迁移无需等待旧版本的每日门过期；读取验证码列失败时不记成功。
- 旧 `kms` 表迁移为 InnoDB；MyISAM 会在权益更新失败时保留已使用标记，违背兑换事务的回滚约定。各条 DDL 独立执行，历史唯一键冲突不阻挡引擎和列宽修复；任何失败或元数据未知都不写完成标记，后续周期重试。退役支付表不在迁移范围内。

## Alternatives considered

**把 ALTER 直接写进 `install.sql` 之外的一次性升级脚本。** 输了：仍然需要有人记得在存量部署上手工执行，与"镜像自动更新"的部署模型冲突。

**在 `ensureSchema()` 里顺带做。** 输了：通知仓库的结构关注点与全站索引/引擎无关，且 ensureSchema 在通知读写路径上同步执行，不适合承载可能锁表的 DDL。

**只为 `udx_users_username` 做特例迁移。** 输了：缺口每加一个索引就复发一次；统一的定义表（`requiredIndexes()`）让后续索引变更只需加一行。

**只修改新安装表定义，或首条 ALTER 失败就停止。** 存量兑换码表不会自动变成事务表，其他历史索引问题还会长期阻挡无关修复。真实旧库结构复现证明 MyISAM 下失败兑换仍消耗卡密。

**仅用在线测试验证执行。** 构建门内没有 MySQL；纯函数和内存 fixture 留在离线门，`RecoveryDatabaseIntegration.php` 在显式指定的空测试库验证新安装及旧列升级。

## Consequences

ALTER 可能取表锁，存量部署在升级前保留备份；清扫每天最多跑一次且只在缺定义时产生语句。新增受管定义必须同步更新 `install.sql` 和迁移计划。执行失败不进入用户任务日志，不写 stamp，并在后续调度周期重试。离线测试的连接隔离见[测试记录](../testing/2026-10-12-schema-test-isolation.md)。
