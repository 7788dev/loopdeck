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

## 性能与容量

`.env.example` 的保守默认值面向 2 核、约 2 GB 内存的服务器；`docker/tune-env.sh` 会在部署时按实际 CPU 与内存重新计算应用内存、MySQL 内存、PHP worker、连接数和调度批量，因此同一镜像在高配机器上不会被小机参数限制：

- PHP-FPM 使用 `ondemand`，空闲时不保留多余 worker；
- MySQL 关闭 Performance Schema 和 MySQL X Plugin，并限制连接数与缓存；
- 应用、数据库和调度器均有 CPU、内存、进程数及日志轮转限制；
- 任务时间字段使用数值类型和复合索引；同一批任务复用用户、账号与任务配置查询；
- 任务按批次、时间预算和 ID 分片执行；调度 worker 数随 CPU 自动增长，并为固定时间任务增加可配置抖动，避免瞬时拥塞；
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

## 容器内测试

```bash
docker compose exec app php tests/NeteaseSdkTest.php
docker compose exec app php tests/NeteaseWorkflowTest.php
docker compose exec app php tests/BilibiliSdkTest.php
docker compose exec app php tests/BilibiliWorkflowTest.php
docker compose exec app php tests/BilibiliTaskExecutorTest.php
```
