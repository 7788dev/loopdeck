# Agent Note: AgentRouter 平台——登录即签到、服务器直连、详情页只读

Status: implemented

## Problem

需要新增 AgentRouter（`https://ps.air-outer.com`，agentrouter.org 面向中国大陆的备用域名，new-api 系）每日签到平台。它与 AnyRouter 同协议但有两点根本差异：绑定凭据用**用户名密码**，登录后用会话 Cookie 与用户 ID 查询余额；**登录 POST 本身就触发每日签到**，不存在独立签到接口（站点 FAQ 明确「签到领 $25 额度，需退出后重新登录到账」）。用户明确要求**只走服务器直连，不用代理**，且每日执行须重新登录并退出。原 Python 脚本以独立 `requests.Session` 保持登录 Cookie，`access_token` 并非必需；`checked_in=True` 表示本次新签到，False 表示今日已签到。原脚本未主动请求退出接口，退出步骤按用户要求补齐。

## Decision

- 新增独立平台 `agentrouter`，镜像 AnyRouter 的账号/任务/调度/通知/配额机制，但 SDK 与服务层独立（`extend/agentrouter/Client.php`、`app/service/AgentRouter{TaskExecutor,AccountInfo}.php`）。
- **登录即签到**：每次执行恰好一个 `POST /api/user/login`（JSON `{username,password}`），随后用同会话 Cookie 与 `New-API-User` 请求只读 `GET /api/user/self` 查余额；存在 `access_token` 时才附 `Authorization`。`checked_in=true` 是「新签到」，false 是「今日已签到」。绝不虚构独立签到接口，绝不重放登录 POST。
- **每日独立会话**：Client 使用内存 Cookie jar，每次登录前清空旧会话；确认登录成功后，无论余额读取成功与否，都在 `finally` 中发送一次 `GET /api/user/logout`，随后清空 Cookie 与用户 ID。退出会改变会话，不经过只读 WAF 重试循环；退出未确认时按有界重试处理，不能标记整个登录/退出流程完成。余额读取失败仍保留已确认签到，下一次执行始终重新登录。
- **服务器直连**：Client 不带代理参数，请求显式 `proxy=''`、`CURLOPT_PROXY=''`、`CURLOPT_NOPROXY='*'` 以击败宿主 `ALL_PROXY`/`NO_PROXY`；TLS 校验开启、禁跟随跳转、响应体上限 1 MiB。无代理池、无 `proxy_mode`。
- **详情页只读**：因为登录就是签到，账号详情页/更新页**绝不登录**，只展示上次调度成功时写回的资料（`Task::storeAgentRouterProfile` 在成功后重新读取最新账号 payload、仅合并 `profile` 再写回，保留凭据）。余额文案注明「最近一次签到时记录」。保存账号是用户显式动作，验证时登录一次（可能提前完成当日签到）是可接受的。
- 凭据（用户名/密码）序列化存于账号 `data`，不回显；错误信息经 `message()` 脱敏（密码、access_token、URL、控制字符）。`/self` 的敏感字段（password、access_token、email）不进入 profile。
- 账号页面和 AJAX 操作通过显式路由分发；用户名在链接中编码，路径参数只解码一次，PHP 已解析的 GET 与 `?s=` 参数不重复解码，保留字面 `%`。防火墙仅对有效 `save` 操作的顶层 `username/password` 按凭据处理，备注、嵌套值及其他字段继续检查，不能因合法密码含 `sleep(3)` 而拒绝保存。
- WAF：响应含 `aliyun_waf` 判为可重试（直连 API 实测可用，不实现 AnyRouter 的 ACW cookie 求解）。凭据错误（401 或「用户名或密码错误」等）判 `account_invalid`，停用账号；网络/WAF/5xx 走有界重试。
- 任务 `sign_in` 为 VIP 功能，纳入既有「VIP 功能」导航组；接入统一调度的 `TaskExecutionLock`、claim/release、`SchedulerRetry` 上限与跨平台账号配额、256 锁池绑定。

## Alternatives considered

- 复用 AnyRouter 平台改域名：鉴权模型（Cookie vs 用户名密码）与签到触发方式不同，强行复用会污染 AnyRouter 的代理/WAF 逻辑，输在耦合。
- 引入代理池/自定义代理：用户明确只要直连，且站点直连可用；代理池是 AnyRouter 专属基础设施，输在多余复杂度与攻击面。
- 详情页实时登录取余额：登录即签到，会在调度之外触发签到并违反「页面访问只读」原则，输在副作用。
- 调用独立 `/api/user/sign_in`：AgentRouter 无此接口（AnyRouter 才有），虚构接口会失败，输在不存在的协议。
- 仅依靠可选 `access_token` 读取余额：原脚本接受仅返回用户 ID 与会话 Cookie 的登录响应，缺少 Cookie 会使成功签到后的余额请求被判未登录。
- 复用昨天的会话或只清本地 Cookie：无法完成用户要求的登录/退出流程；成功登录后须主动退出，退出未确认不能假报整个流程完成。
- 退出失败时重放登录 POST 或无限重试：重复提交每日签到且无法收敛，退出只尝试一次，后续沿用统一有界调度重试。
- 调度成功后不写回余额：详情页将无法展示任何余额（又不能登录），输在可用性。

## Consequences

- 新增 `tests/AgentRouterClientTest.php`（离线伪 HTTP：单次 POST、直连隔离、凭据脱敏、结果分类、重试上限）与 `tests/AgentRouterWorkflowTest.php`（fixture 全流程：保存登录、详情页只读不改凭据、调度写回余额、租户隔离、配额、控件、失效/VIP/删除）。`TemplateRenderingTest` 纳入 agentrouter 列表/详情渲染。
- `tests/AgentRouterSessionTest.php` 在执行请求的 HTTP handler 中校验 Cookie、用户 ID、重新登录与退出顺序，以及余额/退出失败后的会话清理；`tests/AgentRouterHttpTest.php` 通过真实隔离 HTTP 与框架分发验证账号页面、邮箱和特殊用户名、合法表单/JSON 密码及凭据豁免边界。两项测试均先复现失败，再重放验证修复。
- 详情页余额最多滞后到下次签到；这是「页面不触发签到」的必然取舍。
- `storeAgentRouterProfile` 是展示型写回：重新读取最新 payload 仅合并 profile，best-effort，不影响已完成的签到，也不覆盖凭据。
- 安装种子新增 tasks id 24；`Tasks::BUILTIN_TASKS` 含 agentrouter，老安装通过目录同步自动获得该任务。
