# LoopDeck 部署指南

这份指南适用于人工安装，也适用于让 Claude Code、Codex 等 Agent 代部署。默认方案包含 MySQL，无需用户设计数据库名、创建数据库用户或填写数据库密码。容器运维和自动更新细节见 [DOCKER.md](../DOCKER.md)。

## 部署前准备

- 使用 Linux 服务器，安装 Docker Engine 和 Docker Compose 2.20.0 或更新版本；脚本会检查版本及 Docker 是否可用。
- 至少约 1 GB 内存，推荐 2 核、2 GB 或更高配置，并留足镜像和数据库空间。
- 准备服务器访问权限以及一个可用的 Web 端口，默认 `8001`。已有服务时先查看容器、端口及 Compose 项目名，避免占用同一端口或复用别人的数据卷。
- 默认使用内置 MySQL。只有需要云数据库或独立 MySQL 时，才准备外部连接信息。

## 默认安装：无需配置数据库

```bash
git clone https://github.com/7788dev/loopdeck.git
cd loopdeck
sh docker/deploy.sh
```

无需先创建 `.env`，也无需手动构建镜像。脚本会：

1. 生成并保存随机数据库名、专用数据库用户、数据库密码、root 密码和调度密钥。
2. 根据服务器资源调整性能参数，检查 Compose 配置，然后拉取发布镜像。
3. 启动 MySQL，由官方镜像在空数据卷中创建数据库和用户，再启动应用、调度器和更新器。

`.env` 保存的是 MySQL 首次初始化要使用的参数，所以这些参数在容器创建之前生成是正常的。它们不会要求用户预先创建一个 MySQL 容器。

打开 `http://服务器IP:8001`，首页会跳转到 `/install`。页面只填写以下管理员信息：

| 字段 | 要求 |
| --- | --- |
| 联系 QQ | 5–15 位数字 |
| 管理员用户名 | 5–25 位字母、数字、下划线或短横线 |
| 管理员密码 | 6–64 位 |
| 确认密码 | 与管理员密码一致 |

项目没有默认管理员密码。管理员密码与自动生成的数据库密码相互独立。点击安装成功后，使用刚设置的管理员账号登录。安装程序会导入数据库结构、创建管理员并生成持久化的 `config/Db.php`。

安装前容器显示健康只说明 Web 服务已就绪，仍需完成管理员创建。Agent 没有用户的 QQ 或管理员信息时，应请用户完成这一小步，不能编造联系信息或擅自设定公开密码。

## 修改访问端口

若 `8001` 已被其他服务使用，在第一次运行脚本前准备 `.env`：

```bash
cp .env.example .env
```

只修改 `APP_PORT`，例如 `APP_PORT=18001`，其余数据库空值由脚本自动生成。然后运行 `sh docker/deploy.sh`。

## 使用阿里云 RDS 或其他外部 MySQL

先在数据库服务中创建供 LoopDeck 使用的空库和专用账号，授予建表、修改表、索引、读写权限，并允许应用服务器连接。然后复制 `.env.example` 为 `.env`，填写：

```dotenv
MYSQL_HOST=your-mysql.example.com
MYSQL_PORT=3306
MYSQL_DATABASE=your_database
MYSQL_USER=your_database_user
MYSQL_PASSWORD='填写实际数据库密码'
```

运行 `sh docker/deploy.sh` 后，脚本会自动关闭内置 MySQL，只启动 `app`、`scheduler`、`updater`。不需要云数据库的 root 密码；其他随机密钥仍由脚本生成。安装页面依然只要求管理员信息。

请把连接信息写入 `.env`；部署脚本使用保存的配置，避免终端中同名环境变量覆盖数据库选择。密码包含 `$`、`#` 等字符时使用单引号，避免 Compose 把它们当作变量或注释。不要把实际密码贴进部署报告或提交到 Git。

默认内置模式只管理本项目的数据卷；外部模式只使用指定的库，不代替云平台创建数据库实例。已安装站点更换数据库属于数据迁移，单纯修改 `.env` 不会自动迁移数据或替换 `config/Db.php`。

## Agent 验收与交付

部署后检查：

```bash
docker compose ps
curl --fail --silent --show-error http://127.0.0.1:8001/healthcheck
curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' http://127.0.0.1:8001/
docker compose logs --tail=50 app scheduler updater
```

使用自定义端口时相应替换 `8001`。内置模式应有四个服务，外部模式应有三个服务。首次安装前，首页重定向到安装页且页面不要求数据库信息；完成安装后应可登录，重新打开安装页会提示已经安装。日志只能在本机检查，对外汇报时隐藏密钥、Cookie 和其他用户数据。

向用户交付访问地址、安装或登录状态、部署目录、Compose 项目名、运行版本，以及下面的维护命令。说明 `.env` 保存于项目目录、需要保留和备份；无需向用户展开数据库密码。

## 重启、升级与备份

```bash
docker compose ps
docker compose restart app scheduler
git pull --ff-only
sh docker/deploy.sh
```

重复运行部署脚本会复用已有数据库配置和调度密钥；MySQL 数据、管理员账号、任务、日志及应用配置通过数据卷保留。MySQL 初始化变量只在空数据卷上生效，直接修改 `.env` 中的密码不会给现有数据库改密。

升级前备份 `.env`、应用数据卷和 MySQL 数据，并确认备份可恢复。在原目录、原 Compose 项目名下升级。不要使用 `docker compose down --volumes`、`docker volume prune` 或覆盖原 `.env` 来排查安装问题。发现已有数据库卷而凭据丢失时，脚本会停止，应恢复原配置。

如果 Agent 需要进行隔离部署测试，使用单独的目录、`COMPOSE_PROJECT_NAME`、端口和卷，关闭测试实例自动更新；只清理本次测试创建的资源，保留已有服务。

## 常见问题

- **还没启动 MySQL，为什么就有数据库名和密码？** 它们是初始化参数；官方 MySQL 镜像首次启动时使用这些参数建库、建用户。
- **容器健康，但还不能登录？** 首次打开网站，在安装页创建管理员；没有默认管理员账号密码。
- **Docker 安装页仍显示数据库输入框？** 确认宿主机已更新 `compose.yaml` 并重新运行部署脚本；仅更新镜像不会把新增环境变量传进已有容器。
- **外部数据库连接失败？** 检查 `.env` 中的主机、端口、库名、账号密码，以及云数据库白名单和该账号的权限。库需要先创建。
- **提示已有 MySQL 数据卷但配置缺失？** 恢复与该卷对应的原 `.env`。新随机密码无法解锁已有数据库。
