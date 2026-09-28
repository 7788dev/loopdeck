# Agent Note: 后台"立即检查"只写空标记，Docker 仍由 updater 独占

Status: implemented

## Problem

updater 默认每 6 小时检查一次。站长推送新版本、GitHub Actions 镜像构建完成后，
已部署站点最长要等 6 小时才会升级，此前唯一的提前手段是登录宿主机执行
`docker compose pull` 与 `up`。v1.1.25（`fe222b6`，2026-09-04）出于安全考虑移除了
后台"立即更新"按钮：它让 Web 进程携带 Bearer token 调用 Watchtower 的 HTTP 更新接口
并指定镜像，被攻破的管理员会话即可驱动 Docker。需要在不恢复这条链路的前提下，
让站长能提前触发一次检查。

## Decision

- [`app/service/SystemUpdater.php`](../../../../app/service/SystemUpdater.php) 的
  `requestCheck()` 在共享 `app_data` 卷的状态文件旁以 `fopen(..., 'x')` 创建空文件
  `auto-updater-state.json.check-request`；标记已存在时直接复用，文件不含任何内容。
- [`docker/auto-updater.php`](../../../../docker/auto-updater.php) 把定时 `sleep` 改为
  每 1 秒轮询标记；距上次检查开始满 60 秒才删除标记，并执行与定时检查完全相同的
  `runOnce()`。只读取标记是否存在及其 mtime，从不读取内容。
- 常驻循环在状态里写 `accepts_check_requests: true`（`--once` 写 false）；后台只在
  看到该字段时启用按钮，旧版 updater 不会出现"请求已提交却无人处理"。
- 每次检查开始先写合并了上次结果的 `status: checking`，结束时记录
  `trigger: manual|automatic`。检查超过 2 小时仍为 checking、或标记超过 2 分钟无人处理时，
  页面提示查看 updater 日志，而不是一直显示进行中。
- 后台接口 `ajax/updater/{status,check}` 位于带 `CheckAjaxRequest` 中间件的 Ajax 控制器，
  `check` 只接受 POST。

- v1.2.14 起，更新器按版本探测、镜像探测、下载、校验、重启、健康检查和回滚写入阶段，并在阻塞命令与并行网络请求中每秒写心跳。页面每秒读取活动状态，直接更新文案、来源响应数、冷却倒计时和耗时，断线自动重连；空闲时降低到每 10 秒轮询，离开页面停止请求。
- 并行 cURL 句柄使用独立数组项保存，不依赖类型转换后的键；实际多来源回归验证响应不混淆。原版在本机和线上都能保留多个来源，未复现此前怀疑的覆盖问题，因此这是一项健壮性调整，不能宣称修复了已证实的覆盖故障。版本选择仍等待所有配置来源完成或超时，保留最高版本优先原则。

## Alternatives considered

- **恢复"立即更新"，由 Web 调 updater 的 HTTP 接口。** 输因：updater 要暴露带鉴权的网络
  服务，Web 要持有触发密钥并能指定动作，正是 v1.1.25 删除的攻击面。
- **给 app 容器挂载 Docker socket，直接检查或更新。** 输因：等于把宿主机 root 交给 Web 进程。
- **标记携带参数（目标版本、镜像源、强制更新）。** 输因：updater 就得解析并信任 Web 写入的
  内容；空标记让最坏情况只是"检查提前"，版本源、镜像代理、OCI 标签校验和健康检查仍全部
  由 updater 自己的配置决定。
- **用 inotify 代替轮询。** 输因：镜像里没有 inotify 扩展或工具，需要额外安装；每 1 秒一次
  `stat` 的开销可以忽略，轮询间隔缩短后按钮请求通常在 1 秒内被发现（仍受 60 秒冷却约束）。
- **新增环境变量指定标记路径。** 输因：updater 不会同步宿主机的 `compose.yaml`，新变量需要
  站长手动重新部署；从两边已有的 `AUTO_UPDATE_STATE_FILE` 推导路径，只换镜像即可生效。

## Consequences

- 被攻破的管理员会话最多让检查每 60 秒发生一次，无法选择镜像、版本源或命令。
- updater 自身升级到 v1.2.11 后按钮才可用：旧 updater 把应用升到 v1.2.11 时会顺带重建自己，
  新 updater 的首次检查即写入 `accepts_check_requests`。
- 标记后缀在 Web 与 updater 两处各定义一次；
  [`tests/UpdaterCheckRequestTest.php`](../../../../tests/UpdaterCheckRequestTest.php) 用
  "Web 写入 + updater 消费"往返固化路径一致、冷却、只读 mtime、检查中状态与页面按钮状态。
- [`tests/SystemUpdaterTest.php`](../../../../tests/SystemUpdaterTest.php) 继续钉住"立即更新"
  接口、按钮与 `trigger()` 已移除，断言从子串改为精确匹配，以容纳 `ajax/updater`。


## 线上验证（2026-09-28）

发布后通过既有 `requestCheck()`（以应用 UID 82 执行）触发服务器自动更新，app、scheduler、updater 均使用 v1.2.14，app 和数据库保持健康。再通过相同入口触发一次检查，0.47 秒观察到 starting，2.30 秒观察到 versions，3.22 秒观察到 complete；5 个来源全部返回，状态心跳年龄为 0–1 秒。这是服务入口与实际更新器的验证；浏览器的等待倒计时、结果原位更新和断线重连分别以本地浏览器及交互测试验证，不声称已登录线上网页后台。
