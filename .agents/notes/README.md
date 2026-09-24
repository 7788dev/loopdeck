# Agent Notes

这里只放一种文档:记录影响本项目的决策——"为什么"和"放弃了什么"。代码、README、AGENTS.md 解释不了的取舍理由写在这里;AGENTS.md 中的规则通过链接指向这里的 note。

## 路径 = 元数据

notes/{lifecycle}/{class}/yyyy-mm-dd-topic.md

- `proposed/` 未落地提案;`implemented/` 已落地,事实随代码同步更新;`rejected/` 已否决(还能防止一个诱人错误就留,否则删);`archived/` 冻结档案,文件头加 `Archived: yyyy-mm-dd`,永不再改。
- class 封闭集合:`architecture`(源码结构)/ `process`(流程工具)/ `testing`。新增类别需先改本文件。
- 日期 = 决策落地或首次提出的日期(取 git 提交日)。文件间引用一律相对链接。

## 何时写

- 拍板了有取舍的技术决策(选库、定架构、定协议、定目录)→ `implemented/`。
- 大改动动手前需要论证方案 → `proposed/`。
- 方案讨论后被否决 → 原提案移入 `rejected/`,Status 行加否决理由。
- 同一个坑踩了第二次、同类问题被问了第二遍 → 1~3 行祈使句规则写入根 AGENTS.md(或子目录 AGENTS.md),理由链接到这里。
- 机械小改、代码已自解释的实现细节、未发生的计划:不写。
- 已有 note 拥有该决策就更新它,禁止重复建;note 只剩历史价值就移入 `archived/` 并修复所有入链。

## 格式

决策 note 必须包含以下章节,`Alternatives considered` 为强制节(每个落选方案写明输因):

```markdown
# Agent Note: <标题>

Status: implemented | proposed | rejected

## Problem
## Decision
## Alternatives considered
## Consequences
```
