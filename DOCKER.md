# Docker 部署

项目镜像使用 PHP 8.2、Nginx/PHP-FPM Alpine 和 MySQL 8.4。应用以非 root 用户运行，启用 OPcache，配置、运行时文件、会话、上传文件和数据库均通过命名卷持久化。

GitHub Actions 会在 `main` 分支或 `v*` 标签更新时，在 GitHub 上构建 `linux/amd64` 与 `linux/arm64` 镜像并发布到 `ghcr.io/7788dev/loopdeck`。本地和生产服务器不需要构建镜像。

PHP 依赖由根目录的 `composer.json` 声明、由 `composer.lock` 精确锁定。镜像构建会在独立阶段执行 `composer validate`、`composer install --no-dev`、`composer audit` 和全部离线回归测试；`vendor/` 不进入 Git，也不会从开发机复制进镜像。

## 首次启动

1. 推荐直接运行自动部署脚本。它会生成随机数据库密钥、根据宿主机 CPU/内存调整资源参数，并拉取应用镜像：

   ```bash
   chmod +x docker/deploy.sh docker/tune-env.sh
   ./docker/deploy.sh
   ```

   已有 `.env` 时，脚本会保留自定义密钥，只更新性能参数。也可以单独运行 `./docker/tune-env.sh .env`。

2. 若手工部署，复制配置并至少修改以下三项，且不要提交 `.env`：

   ```bash
   cp .env.example .env
   ```

   - `MYSQL_PASSWORD`
   - `MYSQL_ROOT_PASSWORD`
   - `CRON_KEY`（建议使用 48 字节以上随机值）

3. 手工拉取 GitHub 镜像并启动：

   ```bash
   docker compose pull
   docker compose up --no-build --wait --wait-timeout 180
   ```

4. 打开 `http://服务器地址:8001` 完成安装。数据库参数填写：

   | 配置项 | 值 |
   | --- | --- |
   | 数据库地址 | `db` |
   | 数据库端口 | `3306` |
   | 数据库名称 | `.env` 中的 `MYSQL_DATABASE` |
   | 数据库用户名 | `.env` 中的 `MYSQL_USER` |
   | 数据库密码 | `.env` 中的 `MYSQL_PASSWORD` |

安装程序会优先使用容器环境中的 `CRON_KEY`。`scheduler` 容器每 60 秒通过 Docker 内网请求 `/cron/task`，密钥放在请求头中，不会出现在访问日志或公网 URL 中。

从 v1.2.0 起，调度器还会在独立循环中请求 `/cron/notifications`，处理每日总览与通知发送队列。慢速推送服务不会阻塞平台任务调度。非容器部署需要同时定时请求这两个入口，并在 `X-Cron-Key` 请求头中传入密钥。

首次使用通知功能会自动创建 `user_notification_preferences`、`notification_outbox` 和 `notification_task_results` 三张带当前数据库前缀的表，因此应用数据库用户需要建表权限。通知结果保留 30 天，凭据只存储在个人配置中，不进入发送队列。旧 Bark 配置和已经开启的 Epic 邮件订阅会迁移，新的事件类别需要用户自行开启。Bark、PushPlus、WxPusher 无需管理员配置；邮件需在后台“邮件推送设置”开启并填写 SMTP。

从旧版本升级到 v1.2.0 时，应先拉取宿主机代码，再更新 `app`、`scheduler` 和 `updater`，使新增通知循环和 Compose 配置同时生效。升级仅新增通知表，不删除既有账号、任务、日志或持久化卷。

## 性能与容量

`.env.example` 的保守默认值面向 2 核、约 2 GB 内存的服务器；`docker/tune-env.sh` 会在部署时按实际 CPU 与内存重新计算应用内存、MySQL 内存、PHP worker、连接数和调度批量，因此同一镜像在高配机器上不会被小机参数限制：

- PHP-FPM 使用 `ondemand`，空闲时不保留多余 worker；
- MySQL 关闭 Performance Schema 和 MySQL X Plugin，并限制连接数与缓存；
- 应用、数据库和调度器均有 CPU、内存、进程数及日志轮转限制；
- 任务时间字段使用数值类型和复合索引；同一批任务复用用户、账号与任务配置查询；
- 只有设置了有效挂机时间的账号才进入自动任务队列；任务按批次、时间预算和 ID 分片执行；调度 worker 数随 CPU 自动增长；网易云固定时间任务每天在设定时间后随机延迟 3–15 分钟，其他固定时间任务使用可配置抖动；
- 运行日志默认保留 30 天并按索引分批清理。

默认 2 核配置使用 2 个调度 worker，每个 worker 每分钟最多选取 50 条任务，理论选择上限为每天 144,000 条。实际完成量取决于第三方接口延迟、限流和任务类型；“数万条日常任务”应分散到全天，不能把数万次外部请求集中在同一分钟。

不同机器可在 `.env` 中调整：

| 机器 | PHP worker | 调度 worker × 单批 | 应用内存 | MySQL 内存 |
| --- | ---: | ---: | ---: | ---: |
| 1 核 / 1 GB | 2 | 1 × 50 | 自动计算 | 自动计算 |
| 2 核 / 2 GB | 4 | 2 × 50 | 自动计算 | 自动计算 |
| 4 核 / 4 GB | 8 | 4 × 50 | 自动计算 | 自动计算 |
| 8 核及以上 | 16+ | 最多 8 个分片 | 自动计算 | 自动计算 |

Redis 默认不启用。单机部署的主要瓶颈是第三方网络请求和数据库任务检索；增加 Redis 会占用更多常驻内存，却不能提高外部接口吞吐。只有在扩展为多个应用实例、需要共享会话或分布式队列时才建议引入。

## 日常管理

v1.2.1 仅调整邮件依赖与通知运行路径，不修改表结构、Compose 服务或环境变量。升级前备份并验证可恢复性，保留当前镜像及项目名；按 [依赖优化验证记录](docs/DEPENDENCY-OPTIMIZATION.md) 先完成隔离验证，再更新应用服务。备份未完成时，不发布生产自动更新器可见的新版本。

```bash
docker compose ps
docker compose logs -f app scheduler
docker compose pull
docker compose up --no-build --wait --wait-timeout 180
docker compose down
```

不要在有数据时执行 `docker compose down --volumes`，该命令会永久删除应用和数据库卷。

## 自动更新

更新由同一个 LoopDeck 镜像中的 `updater` 容器负责，默认每 6 小时检查一次，不依赖后台按钮或 HTTP 触发接口。更新器挂载 Docker socket、只读项目目录和 `app_data` 状态卷，但只会操作 `app`、`scheduler`、`updater` 三个服务，绝不会删除 MySQL、数据库卷或应用数据卷。

`updater` 不提供 Web 服务，因此 Compose 会关闭基础 PHP/Nginx 镜像继承的 HTTP healthcheck；共享状态卷由应用 UID 82 初始化，更新器仅保留写入该卷所需的 `DAC_OVERRIDE` capability。

每次检查会并行读取多个 `VERSION` 源，选择最高语义版本；同一版本有多个来源时使用延迟最低的来源。随后并行探测多个 GHCR 镜像代理，按延迟顺序拉取并校验 `org.opencontainers.image.version` 标签，避免使用缓存过旧或标签错误的镜像。新容器通过 `/healthcheck` 后才会提交；失败会重新标记旧镜像并回滚。

默认版本源和镜像代理已经写入 `.env.example`。可按服务器网络情况覆盖：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `AUTO_UPDATE_ENABLED` | `true` | 是否启用定时更新 |
| `UPDATE_CHECK_INTERVAL_SECONDS` | `21600` | 成功检查间隔（6 小时） |
| `UPDATE_RETRY_INTERVAL_SECONDS` | `300` | 失败重试间隔（5 分钟） |
| `UPDATE_PROBE_TIMEOUT_SECONDS` | `8` | 版本源/镜像源探测超时 |
| `UPDATE_PULL_TIMEOUT_SECONDS` | `900` | 单个镜像拉取超时 |
| `UPDATE_VERSION_SOURCES` | 多个 GitHub 镜像地址 | 逗号分隔，可增删来源 |
| `UPDATE_IMAGE_REPOSITORIES` | 多个 GHCR 代理 | 逗号分隔，使用仓库名而非标签 |

后台“自动更新状态”页面只读展示最近检查、版本源、镜像源和回滚结果。状态文件位于 `app_data` 卷的 `runtime/auto-updater-state.json`，也可直接查看 updater 日志：

```bash
docker compose ps
docker compose logs --tail=100 updater
```

发布新版本时应同时：

1. 按语义化版本格式更新 `VERSION`；
2. 将代码推送到 `main`；
3. 等待 GitHub Actions 完成 `latest` 和版本号镜像标签的构建。

如果升级内容修改了 `compose.yaml`、卷挂载或环境变量，仍需先在宿主机执行一次 `git pull` 和 `./docker/deploy.sh`，让新的 Compose 配置生效；普通应用代码和镜像更新会由 updater 自动完成。发布后应确认 GitHub Actions 的多架构镜像构建已经成功，更新器会在镜像可用后自动重试。

## 容器内测试

```bash
docker compose exec app php tests/NeteaseSdkTest.php
docker compose exec app php tests/NeteaseWorkflowTest.php
docker compose exec app php tests/NeteaseScheduleTest.php
docker compose exec app php tests/AutomaticScheduleTest.php
docker compose exec app php tests/FaviconTest.php
docker compose exec app php tests/SystemUpdaterTest.php
docker compose exec app php tests/BilibiliSdkTest.php
docker compose exec app php tests/BilibiliWorkflowTest.php
docker compose exec app php tests/BilibiliTaskExecutorTest.php
```
