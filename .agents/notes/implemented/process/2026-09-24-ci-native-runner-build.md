# Agent Note: Docker 多平台镜像改为原生 runner 并行构建

Status: implemented

## Problem

`.github/workflows/docker-image.yml` 最初在单个 runner 上用 QEMU 构建 amd64 与 arm64 镜像。GitHub Actions 缓存过期后,arm64 侧编译 PHP 扩展(尤其是 intl)耗时超过十分钟,镜像发布时长不可控。

## Decision

2026-09-24 提交 `3e3a841` 落地:amd64 与 arm64 在各自的原生 runner 上并行构建,按平台分别缓存,以 digest 推送,最后由独立任务合并出打标签的 manifest list。

## Alternatives considered

**单 runner + QEMU 交叉构建。** 输因:无需额外 runner 配置,但缓存失效时 arm64 的扩展编译超过十分钟;原生 runner 让每平台命中自己的缓存,墙钟时间稳定。

## Consequences

买到的:两平台并行、缓存互不干扰,发布时长稳定。付出的:工作流从一步变三步(两平台构建 + manifest 合并),标签合并依赖 digest 推送的正确性,调试 CI 时需要分别查看两个构建任务。
