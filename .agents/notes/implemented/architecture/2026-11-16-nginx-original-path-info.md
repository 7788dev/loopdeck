# Agent Note: Nginx 保留原始编码路由

Status: implemented

## Problem

隔离测试镜像中，AgentRouter 的邮箱详情链接返回「账号不存在或无权查看」。实际 HTTP 请求的 `REQUEST_URI` 含 `%40`，但 Nginx 传入 PHP 的 `PATH_INFO` 变成 `%2540`；应用按一次解码的约定读取后仍得到 `%40`，无法匹配已存账号。未编码路径和查询参数入口正常。

根因是 `docker/nginx-security.conf` 在 `if` 中匹配 `$request_uri`，再通过 `set ... $1` 保存位置捕获；Nginx 对这次捕获复制中的百分号再次编码。仅运行 PHP 内置服务器的路由回归无法覆盖这层转发。

## Decision

- 用命名捕获 `$loopdeck_original_route` 提取 `$request_uri` 的路径部分，再写入 `PATH_INFO`；保留原始百分号编码且排除查询字符串。
- 应用继续只解码原始路径参数一次，PHP 已解析的查询参数和 `?s=` 不再解码。
- `tests/NginxRoutingTest.php` 在可用的原生 Nginx 上执行同一份转发配置与内部重定向，以无副作用响应观察传入 `PATH_INFO` 的变量。覆盖邮箱、加号、井号、字面百分号、空格、中文和带查询参数的路由。
- Docker 构建的依赖阶段同步复制该配置，使构建内离线套件实际执行 Nginx 回归；未安装 Nginx 的纯 PHP 开发环境明确跳过这一项。

## Alternatives considered

- 在应用中重复 `rawurldecode`：会把用户名中本应保留的字面 `%23`、`%2B` 继续解码，且只掩盖转发层错误。
- 使用 `$uri`：内部重定向后已变为 `/index.php`，也已经过 Nginx URI 归一化，无法保留原始账号标识。
- 只断言配置源字符串：不能证明真实 Nginx 对捕获和转发的编码行为，原有 PHP HTTP fixture 已出现漏检。

## Consequences

- 无 Compose 服务、环境变量或数据库结构变更；更新镜像即可加载修复后的 Nginx 配置。
- 修复前，原生 Nginx 回归因 `%2B` / `%40` 被变为 `%252B` / `%2540` 失败；同一复现在修复后重放，并由测试镜像的实际邮箱详情与签到流程验收。
