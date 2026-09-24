# Agent Note: 发布提交必须同时更新 VERSION 与 README 当前版本

Status: implemented

## Problem

v1.2.8 发布时只改了 `VERSION` 文件，README 的"当前版本"仍停留在 v1.2.7，
版本记录与实际发布脱节，被发现后单独补了 docs 提交。`VERSION` 是自动更新器
判定新版本的唯一来源，README"当前版本"是用户可感知的发布记录，两者不同步
会让已升级站点与文档互相矛盾。

## Decision

版本发布在一个提交内同时完成两处更新：

1. 根目录 `VERSION` 升到新版本号；
2. README"当前版本"整块替换为新版本（日期、亮点、回归测试数、升级影响），
   旧的当前版本块压缩成一行移入"历史更新"顶部。

回滚版本时两处一起回。

## Alternatives considered

- 发布后单独补一个 docs 提交：两次提交之间拉取的站点 VERSION 与 README
  不一致，且依赖"记得补"，实际已被证明会漏，被否决。
- 让后台面板展示仓库链接代替 README 文案：改变面板行为，超出本决策范围，
  被否决。

## Consequences

- 发布 = `VERSION` + README 两处同 commit，漏一处即为不完整发布。
- GH Actions"Read application version"读取的 `VERSION` 与用户文档永远一致。
