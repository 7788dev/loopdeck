# Agent Note: 更新成功必须由运行镜像 ID 证明，而非容器健康

Status: implemented

## Problem

`docker/auto-updater.php` 在重启后只等 `waitForHealthy()`：它取 `compose ps -q app` 的容器并检查 `State.Health`。`compose up -d --pull never app` 在标签未变、配置未变时可能是彻底的 no-op——旧容器原地健康，于是状态写成 `updated`、「已更新到 vX」，而实际运行的仍是更新前的镜像，也不会触发回滚。管理员看到的是一次不存在的升级。

同一状态机的 `app/service/SystemUpdater.php` 还有第二个洞：`checking` 卡死只由 `check_started_at` 超过 2 小时判定，而 `heartbeat_age_seconds` 只是展示；`check_started_at` 缺失或无法解析时保护完全失效。`max(0, time() - $heartbeat)` 又把时间戳超前（宿主机时钟回拨）的心跳压成 0 秒，读起来像刚刚更新过。

## Decision

- 写 `status=updated` 之前调用新的 `verifyReplacement($targetImageId)`：`docker tag` 之后立刻用 `imageId($this->appImage)` 读出**目标镜像 ID**（读不出即判失败），重启后要求 `runningImageId('app')` 与它**完全一致**，否则 `failed`，文案「应用容器未替换为新镜像，已保持原版本运行」，按 `retryInterval` 重试。旧容器仍在正常服务，因此不安排回滚。抽出方法是为了让这个判据能被离线注入的命令桩直接测试（`fetchMany` 用真 curl，整条 `runOnce` 流程无法离线驱动）。

只要求"与拉取前的镜像不同"是不够的：目标为镜像 B、实际运行镜像 C 时同样满足"不同"，于是照样能报「已更新到 vX」——第三种镜像必须被拒绝，测试里显式覆盖 A（旧）、B（目标）、C（无关镜像）三种返回值。
- `checking` 的新鲜度同时看 `check_started_at` 与心跳 `updated_at`：任一超过 `CHECKING_STALE_SECONDS`（7200 秒）即判卡死；任一时间戳超前本机时钟超过 `CLOCK_SKEW_TOLERANCE_SECONDS`（300 秒）时，elapsed 测量不再可信，直接判失败并提示检查宿主机时间同步。心跳阈值取与 2 小时同一量级，因为更新器每个阶段都会写状态，两小时静默只可能是进程已死。

## Alternatives considered

- **把 compose 改成 `--force-recreate`**：能保证换容器，但会无谓重启健康服务，并把失败面从「未更新」扩大到「重启抖动」；且仍然没有验证镜像真的换了。
- **比较 `APP_IMAGE` 标签字符串**：标签是可变的指针，同一 tag 可指向新 digest，标签相同不代表镜像相同；只有 `docker inspect .Image` 的 ID 是运行事实。
- **只放宽 `check_started_at` 的解析**：保留「时间戳超前=新鲜」的错误推断，时钟回拨时依旧不报错。
- **为心跳引入独立的短超时（例如 300 秒）**：单次探测、拉取与健康等待本就可能耗时数分钟，会误报正常升级中的任务为失败。阈值最终沿用 7200 秒：`UPDATE_PULL_TIMEOUT_SECONDS` 被 `intEnv` 夹在 7200 以内，任何单步静默超过 2 小时时整次检查的总时长也必然已越过原有的 2 小时上限，因此心跳分支不会引入原来没有的误报，只补上 `check_started_at` 缺失或无法解析时保护完全失效的洞。

## Consequences

「已更新」只在运行镜像确实改变后出现，未替换时保持原版本并如实失败。管理员在时钟异常时会看到时间同步提示而不是永久 `checking`。回归见 [SystemUpdaterTest](../../../../tests/SystemUpdaterTest.php) 与 [AutoUpdaterTest](../../../../tests/AutoUpdaterTest.php)。
