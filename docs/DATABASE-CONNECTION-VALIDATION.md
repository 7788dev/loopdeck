# v1.2.5 数据库连接信息验证记录

验证日期：2026-09-13。

## 推送前验证

| 检查 | 结果 |
| --- | --- |
| Windows / PHP 8.1 全部 41 个离线回归脚本 | 通过 |
| Linux / PHP 8.2 全部 41 个离线回归脚本 | 通过 |
| 未填写数据库名或密码时分别随机生成，保留自定义值 | 通过 |
| 重复部署复用原有凭据，已有数据库卷缺失密码时停止 | 通过 |
| 新建 MySQL 和应用容器，使用随机配置完成网页安装 | 通过 |
| 启动日志显示地址、端口、数据库名、用户名和查询命令 | 通过 |
| 应用、MySQL、调度器和更新器常规日志不含数据库密码、root 密码和调度密钥 | 通过 |
| 显式查询返回正确密码，查询后常规日志仍不含密码 | 通过 |
| 特殊字符完整保留，控制字符转义，配置读取失败不输出内容或异常详情 | 通过 |
| 无数据库环境变量的旧 Compose 配置下，仅升级应用镜像 | 通过 |

Docker 验证使用独立目录、项目名、回环地址端口、网络和数据卷，并限制 CPU 与内存。推送前的测试镜像基于已发布的 v1.2.4，按新版 Dockerfile 的路径和权限安装本次修改的文件；最终发布镜像由 GitHub Actions 使用仓库 Dockerfile 完整构建，在构建阶段重新运行离线测试。

## 升级兼容性

在测试数据库安装管理员并写入数据标记后，切换到旧 Compose 和 v1.2.4 镜像，再仅替换 `app`、`scheduler`、`updater` 的镜像为 v1.2.5。升级前后核对：

- `.env`、`compose.yaml` 和持久化 `config/Db.php` 的文件指纹不变；
- 管理员记录、数据库名和数据标记不变；
- MySQL 容器 ID 不变，应用健康检查通过；
- `loopdeck-db-info` 优先显示已保存的连接配置，未要求旧 Compose 增加环境变量。

测试期间服务器原有 6 个容器的 ID、镜像和启动时间保持不变；原本健康的应用和 MySQL 继续健康。现有生产更新器已启用，镜像发布后可按原检查周期获取新版本。

## 相关回归命令

```bash
php tests/DatabaseInfoTest.php
php tests/DockerDeploymentTest.php
php tests/InstallDatabaseConfigTest.php
php tests/AutoUpdaterTest.php
php tests/AutoUpdaterSelfRestartTest.php
```

Windows 运行部署回归时，先将 `LOOPDECK_TEST_SHELL` 设置为 Git for Windows 的 `bin/sh.exe`。以上脚本使用临时配置和测试数据，不需要生产数据库凭据。
