# Docker 部署

项目镜像使用 PHP 8.2、Nginx/PHP-FPM Alpine 和 MySQL 8.4。应用以非 root 用户运行，启用 OPcache，配置、运行时文件、会话、上传文件和数据库均通过命名卷持久化。

GitHub Actions 会在 `main` 分支或 `v*` 标签更新时，在 GitHub 上构建 `linux/amd64` 与 `linux/arm64` 镜像并发布到 `ghcr.io/7788dev/loopdeck`。本地和生产服务器不需要构建镜像。

## 首次启动

1. 推荐直接运行自动部署脚本。它会生成随机数据库密钥、根据宿主机 CPU/内存调整资源参数，并且只拉取 GitHub 镜像：

   ```bash
   chmod +x docker/deploy.sh docker/tune-env.sh
   ./docker/deploy.sh
   ```

   已有 `.env` 时，脚本会保留自定义密钥，只更新性能参数。也可以单独运行 `./docker/tune-env.sh .env`。

2. 若手工部署，复制配置并至少修改以下四项，且不要提交 `.env`：

   ```bash
   cp .env.example .env
   ```

   - `MYSQL_PASSWORD`
   - `MYSQL_ROOT_PASSWORD`
   - `CRON_KEY`（建议使用 48 字节以上随机值）
   - `UPDATE_TOKEN`（后台一键更新服务的内网鉴权密钥，建议使用 48 字节以上随机值）

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

```bash
docker compose ps
docker compose logs -f app scheduler
docker compose pull
docker compose up --no-build --wait --wait-timeout 180
docker compose down
```

不要在有数据时执行 `docker compose down --volumes`，该命令会永久删除应用和数据库卷。

## 后台一键更新

仓库根目录的 `VERSION` 是程序版本号。管理员进入“站长后台 → 程序更新”时，应用会读取本地版本，并通过 GitHub API 获取 `main` 分支上的 `VERSION` 进行比较；如果主版本源不可用，会自动回退到 jsDelivr 和 GitHub Raw。

检测到新版本后，后台会通过 Docker 内网调用 `updater` 容器。更新器只选择带 `com.centurylinklabs.watchtower.enable=true` 标签的 `app` 和 `scheduler`，拉取 `APP_IMAGE` 对应的新镜像并重建容器；MySQL、应用数据卷和数据库卷不会被删除。更新器端口不会映射到宿主机，调用还必须携带 `.env` 中的 `UPDATE_TOKEN`。

发布新版本时应同时：

1. 按语义化版本格式更新 `VERSION`；
2. 将代码推送到 `main`；
3. 等待 GitHub Actions 完成 `latest` 和版本号镜像标签的构建。

如果升级内容修改了 `compose.yaml`、卷挂载或环境变量，仍需先在宿主机执行一次 `git pull` 和 `./docker/deploy.sh`，让 Compose 配置本身生效。普通应用代码更新可以直接从后台完成。

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
