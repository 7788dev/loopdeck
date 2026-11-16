# Agent Note: 展示型全站统计走 60 秒共享快照

Status: implemented

## Problem

用户仪表盘（`Console::index`）、管理员仪表盘（`System::index`）和公开首页（`Index::index`）每次浏览都各自执行全站聚合查询：`Users::userCount()`、`Accounts::accountCount()`、`Jobs::jobCount()`（无 WHERE 的全表 COUNT）、`Info::executeCount()`。这些数字只用于展示，却随每次页面浏览重复查库；公开首页的每个匿名访客也会触发。首页的 `Weblist::start_Time()` 另外再查一次 `weblist.start_time`，而 `LoadConfigs` 早已把整行站点记录缓存进 `config('web')`。任务目录首次访问时 `Tasks::ensureBuiltinTasks()` 对每个内置任务逐条 `find()`（共 5 次 SELECT），PHP-FPM 每请求都是新进程状态，列表页每次都要重付这笔开销。

## Decision

- 新增 `app\service\SiteStatistics::counters()`：四个展示计数合并为一条 `site_display_statistics` 缓存（File 驱动，TTL 60 秒，与 `LoadConfigs` 的站点配置缓存同节奏），三个控制器统一读它。个人额度 `quota_used` 仍每次实时查询，因为它紧随用户自己的操作且与配额文案相关。
- `Weblist::start_Time()` 改读 `config('web.start_time')`，不再查库；管理员保存站点信息时 `LoadConfigs::invalidate()` 已负责刷新。
- `ensureBuiltinTasks()` 的存在性检查合并为每平台一条 `whereIn` SELECT； legacy 描述同步、管理员自定义保留、插入竞态回退逻辑不变。
- 展示数字允许 60 秒内的陈旧：它们不参与任何配额、权限或调度判定。不做写路径主动失效，避免把注册、调度等高频写路径耦合到展示缓存上。

## Alternatives considered

- 每个模型方法各自 `Cache::remember`：仪表盘要读 4 个缓存条目，且四个数字可能来自不同时刻；合并快照一次读取、彼此一致，输在碎片化。
- 写路径（注册、任务执行）主动失效统计缓存：展示数字不需要实时，主动失效会把高频写路径和缓存键耦合起来，收益为零，输在复杂度。
- 给 `safe_html()` 加记忆化缓存：基准显示冷热均约 10µs，且每请求每条公告只渲染一次，缓存命中机会极少，输在收益可忽略。
- 首页单独缓存 `userCount`：首页缓存未命中时仍要算全部四个计数（每分钟至多一次），单独键反而多一个缓存条目，输在碎片化。

## Consequences

- 仪表盘与首页的热路径不再触发全站聚合查询；`tests/SiteStatisticsTest.php` 用 SQL 监听固定冷快照 4 条查询、热读取 0 条、仪表盘只剩个人额度 1 条 COUNT，并固定内置目录同步每平台 1 条 SELECT。
- 展示数字最多滞后 60 秒；需要实时数字的场景（个人额度）不受影响。
- `SiteStatistics` 是展示统计的唯一入口；新增全站展示计数应并入该快照，而不是在控制器里新发聚合查询。