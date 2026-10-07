# Agent Note: PR 轻量测试 job 与 PHP 8.2 平台契约

Status: implemented

## Problem

离线套件只存在于 Docker 构建（`docker-image.yml` 的 `dependencies` 阶段），PR 的唯一信号是双平台镜像构建：同一套测试在 amd64 与 arm64 原生 runner 上各跑一遍（PR 改动 `app/`/`tests/` 时平台缓存必然失效,重复付费),测试前还必须先装 PHP 扩展与 Node,缓存过期时墙钟逼近 build job 的 30 分钟上限。同时 `composer.json` 允许 `^8.1` 而镜像固定 PHP 8.2,本地(如 PHP 8.1.34)通过测试不代表镜像里的 8.2 也接受这些写法,依赖解析也可能因运行 composer 的 PHP 版本不同而漂移。

## Decision

2026-10-07 双改:

1. `docker-image.yml` 新增 `test` job(仅 `pull_request` 触发):ubuntu-24.04 + `shivammathur/setup-php` PHP 8.2 + pdo_sqlite,先执行与 Dockerfile 一致的 `composer validate --strict --no-check-publish`,再安装锁定依赖并运行离线套件(`for test_file in tests/*Test.php; do php "$test_file"; done` + `node tests/FrontendInteractionTest.js`),composer 按 lock 缓存;`build` job `needs: test` 并以 `needs.test.result == 'success' || 'skipped'` 放行——PR 上锁文件校验或测试失败则两台重型构建根本不启动,push/tag 上该 job 跳过、发版路径的镜像内测试闸门原样保留。
2. `composer.json` 增加 `config.platform.php: 8.2.0`,让依赖解析以 8.2 为基准。该配置参与 lock 的 `content-hash`;使用与镜像一致的 Composer 2.10.2 执行 `composer update --lock --no-install --no-scripts`,同步哈希和 `platform-overrides`,保留全部锁定依赖版本。本地 PHP 的实际执行版本不受此配置改变。
3. `publish` 的 job 条件显式包含 `!cancelled()`、非 PR 事件与 `needs.build.result == 'success'`。GitHub 默认的 `success()` 会把 PR 专用测试的跳过状态沿依赖链传播;仅在 `build` 覆盖条件仍会使 `publish` 跳过。发布确认同时检查两个平台构建和 `publish` 均成功,再验证 GHCR 版本标签,不能只看整个 workflow 显示成功。

## Alternatives considered

**`dependencies` 阶段加 `ARG RUN_TESTS`,arm64 消费 amd64 的测试结果。** 输因:省下的只是重复执行,却把「每个发布平台镜像都构建自实跑过套件的阶段」这一保证撕掉;测试层不向 runtime 阶段输出产物,收益小、承诺损失大。

**PR 也继续只靠镜像构建出信号。** 输因:纯 PHP 逻辑改动的反馈被构建前置拖长,且每 PR 双倍执行同源测试,粒度与成本不匹配。

**同时把 `require.php` 提到 `^8.2`。** 输因:提高项目声明的最低运行版本属于另一项兼容性变更;本次仅固定镜像与依赖解析基准,保留既有 `^8.1` 声明。

**只修改 `config.platform`,不刷新锁文件。** 输因:Composer 2.10.2 的严格校验会报锁文件过期并以状态码 1 退出,阻塞 Dockerfile 的依赖阶段。平台覆盖配置与锁文件同步提交,轻量 PR job 也执行同一校验。

**只在 `build` 放行跳过的测试。** 输因:首次推送验证中两个平台构建均成功,但 `publish` 被默认状态条件跳过,整个 workflow 仍显示成功。发布任务也覆盖默认状态条件,并显式要求构建成功;取消或构建失败时仍不发布。

## Consequences

买到:PR 得到与镜像同源的依赖校验和测试信号;锁文件过期或测试失败的 PR 不再启动两台构建 runner;依赖解析排除要求高于 PHP 8.2.0 的包,实际兼容性由 PHP 8.2 的测试确认。付出:PR 多一个 job 的 runner 时间;本地 PHP 8.1 测试通过不能代替 8.2 CI;`push`/`tag` 路径下镜像内测试仍是唯一执行点,这是既有决策的保留而非新增。
