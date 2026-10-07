# Agent Note: cron 鉴权收敛到 CheckCronAccess 单点

Status: implemented

## Problem

cron 应用的鉴权曾有四套并存写法:`app\middleware\CheckCronAccess`(env `CRON_KEY` 优先、回退 config,收 header+query)、`Netease`/`Bilibili`/`Heybox` 控制器各查一遍(只收 query、只比 `config('sys.cronkey')`)、`Task`/`Epic`/`Notifications` 又一套(`getenv ?: config`,收 header+query)。控制器自检与中间件恰好同值,靠的是安装器把 env 密钥镜像进 `cloud_configs`([install createRuntimeConfig](../../../../app/install/controller/Index.php))这一隐性耦合。管理员在「系统任务配置」里修改 cronkey 会写入 `cloud_configs`,而 env 优先的中间件仍认旧值、query-only 的三个控制器又只认新值——没有任何密钥能同时通过两道闸,netease/bilibili/heybox 端点被锁死。

## Decision

2026-10-07 起鉴权只发生在 `app\middleware\CheckCronAccess`(app/cron/middleware.php 应用级全局):凭证取 `X-Cron-Key` 请求头(优先)或 `?cronkey=` 查询参数,与 env `CRON_KEY` 或主站安装写入的 `config('sys.cronkey')` 任一 `hash_equals` 匹配即通过。`Task`/`Netease`/`Bilibili`/`Heybox`/`Epic`/`Notifications` 六个控制器删除各自的凭证自检。CronControllerSafetyTest 断言六个控制器不再出现 `CronKey Access Denied!`;FeatureRemovalTest 以桩请求断言中间件的双源行为(env 与 config 任一通过、双通道 header/query 等价、其余 403、env 轮换不锁死已安装密钥)。

## Alternatives considered

**保持 env 优先、config 仅回退。** 输因:容器部署 env 恒存在,面板上修改的密钥永远不生效,字段变成摆设;轮换只能重发环境变量,失去面板轮换能力,且锁死问题依旧。

**删除 query 参数通道,只留 header。** 输因:部分外部监控平台发不了自定义请求头;保留 query 通道由 cron 路由白名单与中间件统一把关,文案上不再宣传(同日清理了 admin 视图里拼 `?cronkey=` 的监控地址)。

## Consequences

买到:鉴权语义只有一个家,轮换走 env 或面板任一通道都生效且互不锁死;控制器不再携带会与中间件漂移的凭证逻辑。付出:绕过中间件直接实例化控制器(如离线测试)不再有任何鉴权,这是预期;query 通道继续存在,靠文档与文案约束而非代码禁止。
