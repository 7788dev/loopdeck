---
name: release-version
description: Use when releasing or publishing a new LoopDeck version — 发版、发布新版本、更新版本号、升版本、bump VERSION、推送到 GitHub/gh、构建或发布镜像、确认 GHCR 镜像编译完成 — to cut the release commit, push main and confirm the multi-arch image that deployed updaters pull
---

# 发布新版本

把已提交的功能或修复发布为新版本，并确认已部署站点的 updater 能拉到镜像。只改文档、站点无需升级时不发版。

## 步骤

1. 确认功能提交已完成：`git status --short` 只剩与发布无关的未跟踪文件，`git log --oneline origin/main..HEAD` 列出待发布的提交。
2. 跑全部离线回归（Windows 先按 [AGENTS.md](../../../AGENTS.md) 设置 `LOOPDECK_TEST_SHELL`）：
   `for t in tests/*Test.php; do php "$t" >/dev/null 2>&1 || echo "FAIL $t"; done`
   有任何 FAIL 先修复，不进入下一步。
3. `cat VERSION`，1.2.x 系列按补丁号 +1；`VERSION` 只写版本号一行。
4. 按[发布提交规则](../../notes/implemented/process/2026-09-25-release-updates-version-and-readme.md)重写 README `## 当前版本`，旧版本块压成一行插到 `## 历史更新` 顶部；最后一条要点写离线回归总数，以及是否修改数据库结构、Compose 服务与环境变量。
5. 再跑一次第 2 步（`SystemUpdaterTest` 会读取 `VERSION`）。
6. 发版提交只含这两个文件：`git add VERSION README.md && git commit -m "chore: release vX.Y.Z"`（附会话要求的 Co-Authored-By 行）。
7. `git push origin main`。
8. 找到这次推送触发的构建并等它结束：
   `gh run list --workflow docker-image.yml --branch main --limit 5 --json databaseId,headSha --jq ".[] | select(.headSha==\"$(git rev-parse HEAD)\") | .databaseId"`
   然后 `gh run watch <id> --exit-status`。
   - 没有输出 id：run 还没创建，几秒后重跑上一条命令。
   - 失败：`gh run view <id> --log-failed` 定位，修复后重新提交、推送，回到第 8 步。
9. `php .agents/skills/release-version/scripts/check-ghcr-image.php X.Y.Z` 必须输出 linux/amd64、linux/arm64 两行并以 0 退出；返回 404 或版本标签不符时，publish 任务未完成或失败，回到第 8 步。
10. 告知用户：已部署站点会在下一次定时检查时升级；也可在后台「自动更新状态」点「立即检查」立即升级（updater 需为 v1.2.11 及以上）。

## 禁止

- 不在发版提交里夹带代码改动，不用 `git add -A`。
- 本次 HEAD 的 CI 未全绿、第 9 步未通过前，不报告"发布完成"。
- 不 force push `main`。
