# Agent Note: 调度修复使用隔离容器验证

Status: implemented

## Problem

SQLite 离线测试不能证明真实 MySQL 并发行为。直接从 Windows 工作区打包又会把 CRLF 带进 PHP nowdoc 生成的 shell 脚本；默认 Git 归档也可能应用本机 `core.autocrlf`，使 Linux 容器的测试脚本失败。

## Decision

- 服务器测试使用新建的 PHP 8.2 应用容器、MySQL 8.4 容器、独立数据卷和 internal 网络。数据库账号只授权测试库；测试环境没有生产数据卷或生产 Docker socket。
- 用 `git -c core.autocrlf=false archive` 传输已提交源代码，验证归档内的 PHP/shell 使用 LF。同步时保留容器中 config/runtime 的卷符号链接，逐个同步代码目录。
- 测试容器执行 58 组 PHP 回归和 Node 前端检查；CLI 测试清除仅用于关闭测试站自动更新界面的环境覆盖。
- 真实 HTTP/FPM/MySQL 检查通过 32 项断言：登录会话、鉴权、退役路由、统一与旧调度入口竞争、补挂保护、任务恢复、VIP 与平台范围隔离、24 个并发维护请求、SQL 冲突注入和后续恢复。第三方适配器替身仅通过测试容器的 PHP prepend 注入；生产源码没有测试开关，也没有接触生产账号的上游任务。
- 初次安装通过实际安装接口完成。核心源码哈希与提交 `cdb7d08` 的 Git blob 一致。发布前备份数据库与数据卷，并把数据库备份恢复到另一个隔离库验证可恢复性。

## Alternatives considered

**只跑已有离线测试。** 不能覆盖 MySQL 锁竞争、FPM 多进程与不同 HTTP 应用之间的共享锁目录。

**使用生产账号重复签到或领奖。** 会消耗当天任务、影响生产记录，还无法稳定制造接口错误、延迟和竞争窗口。控制第三方响应可验证业务状态处理，真实数据库与 HTTP 请求仍独立运行。

**直接归档 Windows 工作区。** PHP 源文件中的多行 shell 字面量会带入 CRLF；源码需要按 Linux Git 签出语义传输。

## Consequences

验证覆盖内部调度和部署运行链，不把模拟的第三方响应宣称为外部平台已经真实发放奖励。测试结果通过后才发布镜像，生产升级保留原卷与配置；最后清理本次隔离容器及用户指定的旧测试容器。
