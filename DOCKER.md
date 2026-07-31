# Docker 部署

项目提供 PHP 8.2 + Apache 与 MySQL 8.4 的 Docker Compose 配置。数据库、安装配置、运行缓存、登录会话和上传文件均使用命名卷持久化。

GitHub Actions 会在 `main` 分支和 `v*` 版本标签更新时构建 `linux/amd64`、`linux/arm64` 镜像，并发布到 `ghcr.io/7788dev/loopdeck`。Pull Request 只验证镜像能否构建，不会发布。

## 首次启动

1. 准备环境配置：

   ```powershell
   Copy-Item .env.example .env
   ```

   Linux 或 macOS 使用：

   ```bash
   cp .env.example .env
   ```

2. 修改 `.env` 中的 `MYSQL_PASSWORD` 与 `MYSQL_ROOT_PASSWORD`。两个密码应不同，且不要提交 `.env`。

3. 选择一种启动方式。

   拉取 GitHub Container Registry 中的镜像：

   ```bash
   docker compose pull app
   docker compose up --no-build --wait --wait-timeout 180
   ```

   或构建当前目录中的源码：

   ```bash
   docker compose up --build --wait --wait-timeout 180
   ```

4. 打开 `http://127.0.0.1:8001` 完成网页安装。安装页数据库参数填写：

   | 配置项 | 值 |
   | --- | --- |
   | 数据库地址 | `db` |
   | 数据库端口 | `3306` |
   | 数据库名称 | `.env` 中的 `MYSQL_DATABASE` |
   | 数据库用户名 | `.env` 中的 `MYSQL_USER` |
   | 数据库密码 | `.env` 中的 `MYSQL_PASSWORD` |

若修改了 `APP_PORT`，请使用对应端口访问。MySQL 默认只允许 Compose 内部网络访问，不对宿主机开放端口。

## 日常管理

```bash
# 查看服务状态
docker compose ps

# 查看应用日志
docker compose logs -f app

# 重新构建并启动
docker compose up --build --wait --wait-timeout 180

# 停止服务，保留数据
docker compose down
```

应用数据存放在 `app_data` 卷，数据库存放在 `db_data` 卷。执行 `docker compose down --volumes` 会永久删除这两个卷，仅应在确认不再需要数据时使用。

## 容器内测试

```bash
docker compose exec app php tests/NeteaseSdkTest.php
docker compose exec app php tests/NeteaseWorkflowTest.php
docker compose exec app php tests/BilibiliSdkTest.php
docker compose exec app php tests/BilibiliWorkflowTest.php
docker compose exec app php tests/BilibiliTaskExecutorTest.php
```

定时任务仍通过后台“系统设置 - 任务调度”页面显示的统一调度 URL 触发。部署到公网时，应使用 HTTPS 并妥善保护 URL 中的 `cronkey`。
