# Agent Note: AgentRouter 平台——登录即签到、固定出口、详情页只读

Status: implemented

## Problem

需要新增 AgentRouter（`https://ps.air-outer.com`，agentrouter.org 面向中国大陆的备用域名，new-api 系）每日签到平台。绑定凭据用用户名密码，登录后用会话 Cookie 与用户 ID 查询余额；登录 POST 本身就触发每日签到，不存在独立签到接口（站点 FAQ 明确「签到领 $25 额度，需退出后重新登录到账」）。每日执行须重新登录并退出。原 Python 脚本以独立 `requests.Session` 保持登录 Cookie，`access_token` 并非必需；`checked_in=True` 表示本次新签到，False 表示今日已签到。原脚本未主动请求退出接口，退出步骤按用户要求补齐。

最初采用服务器直连；目标服务器实测备用域名被交互式人机验证阻断，主域名存在 DNS 污染且正确地址的直连 TLS 被重置，原脚本的代理列表也为空。用户随后明确允许 AG 配置代理，并提供订阅用于转换为可用出口，该授权取代最初的禁止代理约定。

## Decision

- 新增独立平台 `agentrouter`，镜像 AnyRouter 的账号/任务/调度/通知/配额机制，但 SDK 与服务层独立（`extend/agentrouter/Client.php`、`app/service/AgentRouter{TaskExecutor,AccountInfo}.php`）。
- **登录即签到**：每次执行恰好一个 `POST /api/user/login`（JSON `{username,password}`），随后用同会话 Cookie 与 `New-API-User` 请求只读 `GET /api/user/self` 查余额；存在 `access_token` 时才附 `Authorization`。`checked_in=true` 是「新签到」，false 是「今日已签到」。绝不虚构独立签到接口，绝不重放登录 POST。
- **每日独立会话**：Client 使用内存 Cookie jar，每次登录前清空旧会话；确认登录成功后，无论余额读取成功与否，都在 `finally` 中发送一次 `GET /api/user/logout`，随后清空 Cookie 与用户 ID。退出会改变会话，不经过只读 WAF 重试循环；退出未确认时按有界重试处理，不能标记整个登录/退出流程完成。余额读取失败仍保留已确认签到，下一次执行始终重新登录。
- **余额来源**：登录响应可携带额度、已用额度和请求数的零占位值，实测余额接口被网站验证阻断时会暴露该问题。这三项指标仅使用成功的 `/self` 响应；请求失败或缺少指标时显示「暂不可用」，不能从登录响应补零。成功查询确认的零值仍正常显示，身份字段可继续来自登录响应。
- **固定会话出口**：匿名预检后，Client 将代理与官方域名保存为只读属性，登录、余额和退出使用同一出口。请求显式覆盖宿主代理与 bypass，TLS 校验开启、禁跟随跳转、响应体上限 1 MiB。站长配置位置、账号平台代理/自有选择、订阅转换与节点记录已由[私有代理决策](2026-11-16-agentrouter-private-proxy-preferences.md)替代早期部署环境方案。
- **官方域名**：仅接受 `https://ps.air-outer.com`（默认）和 `https://agentrouter.org`，不能把凭据发送给任意地址；注册网址使用用户指定的 AFF 链接。
- **详情页只读**：因为登录就是签到，账号详情页/更新页**绝不登录**，只展示上次调度成功时写回的资料（`Task::storeAgentRouterProfile` 在成功后重新读取最新账号 payload、仅合并 `profile` 再写回，保留凭据）。余额文案注明「最近一次签到时记录」。保存账号是用户显式动作，验证时登录一次（可能提前完成当日签到）是可接受的。
- 凭据（用户名/密码）序列化存于账号 `data`，不回显；错误信息经 `message()` 脱敏（密码、access_token、URL、控制字符）。`/self` 的敏感字段（password、access_token、email）不进入 profile。
- 账号页面和 AJAX 操作通过显式路由分发；用户名在链接中编码，路径参数只解码一次，PHP 已解析的 GET 与 `?s=` 参数不重复解码，保留字面 `%`。防火墙仅对有效 `save` 操作的顶层 `username/password` 按凭据处理，备注、嵌套值及其他字段继续检查，不能因合法密码含 `sleep(3)` 而拒绝保存。
- WAF：响应含 `aliyun_waf` 判为可重试，不实现交互验证码求解。凭据错误（401 或「用户名或密码错误」等）判 `account_invalid`，停用账号；网络/WAF/5xx 与无效的部署代理配置走有界重试。HTTP 异常和代理认证内容不转发给用户。
- 任务 `sign_in` 为 VIP 功能，纳入既有「VIP 功能」导航组；接入统一调度的 `TaskExecutionLock`、claim/release、`SchedulerRetry` 上限与跨平台账号配额、256 锁池绑定。

## Alternatives considered

- 复用 AnyRouter 平台改域名：鉴权模型（Cookie vs 用户名密码）与签到触发方式不同，强行复用会污染 AnyRouter 的代理/WAF 逻辑，输在耦合。
- 坚持直连：目标服务器被交互验证与连接重置阻断，无法满足真实签到验收；用户已授权固定代理，原禁止代理约定不再适用。
- 自动公开代理池：AG 仅使用站长配置的来源，不扩展 AnyRouter 的公开列表；代理选择的后续变更见私有代理决策。
- 直接把订阅 URL 当 HTTP / SOCKS5 地址：订阅返回的是节点配置，不能用于 HTTP CONNECT；由受限本地代理运行时转换，节点数据与认证留在站点私有数据中。
- 详情页实时登录取余额：登录即签到，会在调度之外触发签到并违反「页面访问只读」原则，输在副作用。
- 调用独立 `/api/user/sign_in`：AgentRouter 无此接口（AnyRouter 才有），虚构接口会失败，输在不存在的协议。
- 仅依靠可选 `access_token` 读取余额：原脚本接受仅返回用户 ID 与会话 Cookie 的登录响应，缺少 Cookie 会使成功签到后的余额请求被判未登录。
- 余额读取失败时回退到登录响应的指标：登录可返回零占位值，未确认的余额会被误报为 `$0.00`；仅保留身份与已确认签到，把指标标为未知。
- 复用昨天的会话或只清本地 Cookie：无法完成用户要求的登录/退出流程；成功登录后须主动退出，退出未确认不能假报整个流程完成。
- 退出失败时重放登录 POST 或无限重试：重复提交每日签到且无法收敛，退出只尝试一次，后续沿用统一有界调度重试。
- 调度成功后不写回余额：详情页将无法展示任何余额（又不能登录），输在可用性。

## Consequences

- 新增 `tests/AgentRouterClientTest.php`（离线伪 HTTP：单次 POST、直连隔离、凭据脱敏、结果分类、重试上限）与 `tests/AgentRouterWorkflowTest.php`（fixture 全流程：保存登录、详情页只读不改凭据、调度写回余额、租户隔离、配额、控件、失效/VIP/删除）。`TemplateRenderingTest` 纳入 agentrouter 列表/详情渲染。
- `tests/AgentRouterSessionTest.php` 在执行请求的 HTTP handler 中校验 Cookie、用户 ID、重新登录与退出顺序，以及余额/退出失败后的会话清理；`tests/AgentRouterHttpTest.php` 通过真实隔离 HTTP 与框架分发验证账号页面、邮箱和特殊用户名、合法表单/JSON 密码及凭据豁免边界。两项测试均先复现失败，再重放验证修复。
- `tests/AgentRouterProxyTest.php` 验证固定出口、宿主代理与 bypass 隔离、官方域名限制、WAF 不重放 POST、保存/调度两入口一致与连接后配置快照。原有 AG 环境变量与默认直连已撤销，由后台私有配置及账号选择替代。
- 详情页余额最多滞后到下次签到；这是「页面不触发签到」的必然取舍。
- `storeAgentRouterProfile` 是展示型写回：重新读取最新 payload 仅合并 profile，best-effort，不影响已完成的签到，也不覆盖凭据。
- 安装种子新增 tasks id 24；`Tasks::BUILTIN_TASKS` 含 agentrouter，老安装通过目录同步自动获得该任务。
