# Agent Note: 离线结构测试不加载安装数据库

Status: implemented

## Problem

SchemaMaintenanceTest 原来初始化完整应用，再调用真实维护入口；已安装配置可用时，这个离线测试会修改配置指向的数据库。在隔离容器里安装应用后，移除测试库的任务唯一索引并运行原测试，两轮都实际重建了该索引。

## Decision

该测试使用 FunctionalDatabaseBootstrap 的内存 SQLite，断言 PDO 驱动确为 sqlite，并在维护后验证 fixture 未变。纯 DDL 计划仍使用 cloud_ 前缀；真实 MySQL 行为只在显式指定的空 loopdeck_qa_* 数据库中验证。

更新器无效状态用例显式开启被测更新器，避免继承测试容器的 `AUTO_UPDATE_ENABLED=false` 而误报。`HttpSmoke.php` 要求环回地址、测试库前缀和显式测试管理员凭据，验证安装后的权限、页面、Cron 和上传文件执行边界。

## Alternatives considered

**捕获数据库异常。** 连接成功并执行成功时没有异常，不能阻止误写。

**依赖开发机数据库不可用。** 已安装环境与 CI 的配置不同，网络不可用不是隔离边界。

## Consequences

离线套件不再为这个测试读取安装配置或等待 MySQL 连接超时。真实 MySQL 集成测试保持显式运行，服务器验证使用独立容器、内网和卷。
