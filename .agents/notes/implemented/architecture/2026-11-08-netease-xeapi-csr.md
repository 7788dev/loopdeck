# Agent Note: 网易云 XEAPI 对齐上游 4.41.1 的 CSR 请求形态

Status: implemented

## Problem

本地请求层原先固定在 api-enhanced 4.40.1（commit `f5ce55b`），XEAPI 仍是 BSR 形态：
旧 key 端点 `/api/gorilla/anti/crawler/security/key/get`、表单 body 用 base64、
掩码先 base64 再旋转、外层密文字段为 `B`。

上游 api-enhanced 4.41.0（2026-10-03，PR #276）把 XEAPI 改为适配新 Android
请求方式的 CSR：key 端点 `/api/bsr/sk/get`、`appVersion=9.5.61`、掩码直接旋转
原始字节、表单 body 以 UTF-8 `content` 字段传输、外层密文字段为无填充
base64url `C`。2026-11-08 的匿名和未登录实测表明旧 BSR 仍被服务端接受，
但上游已经切换，不能等到旧端点被关闭才迁移；同时 CSR 握手失败又不能中断
所有网易云任务。

## Decision

- [Crypto](../../../../extend/netease/sdk/Crypto.php) 的 `xeapi()` 增加
  `protocol` 选项：`csr` 返回 `C` 并使用原始字节旋转与 `content` 表单；
  `bsr` 保留旧形态。默认仍为 `bsr`，由客户端显式选择。
- [Client](../../../../extend/netease/sdk/Client.php) 默认
  `xeapi_protocol=csr`、`xeapi_fallback=true`。CSR 密钥握手失败时，才在发送
  业务请求之前回退到 BSR key 端点与 BSR 报文；业务请求一旦发出，失败后绝不
  跨协议重放，避免重复分享、评论等有副作用操作。
- 设置 `xeapi_protocol=bsr` 可强制旧形态，`auto` 先试 CSR 再试 BSR，
  `xeapi_fallback=false` 可让 CSR 密钥失败直接暴露。
- CSR 与 BSR 使用独立缓存文件（`xeapi_public_key_csr.json` 与旧
  `xeapi_public_key.json`），避免复用不同协议的公钥状态；显式传入的
  `xeapi_public_key` 必须带 `protocol`，缺省按旧 BSR 处理。
- [回归](../../../../tests/NeteaseSdkTest.php) 覆盖 CSR 报文/`content`/base64url
  `C`、CSR key 端点与 appVersion、CSR→BSR 回退、关闭回退、强制 BSR 和缓存隔离。

## Alternatives considered

- **全量切换 CSR、不保留 BSR 回退**：新 key 端点临时不可用或网络抖动会让所有
  网易云 XEAPI 任务失败；输。
- **继续默认 BSR**：与上游主路径背离，旧端点被关闭时再迁移会被动；输。
- **CSR 业务请求失败后用 BSR 重放**：分享、评论、回复等请求有副作用，重放可能
  产生重复数据；输。
- **CSR/BSR 共用一份 key 缓存**：两种协议的 key 版本与 `sk` 可能不同，复用会让
  握手随机失败；输。
- **立即接入 NEAPI**：NEAPI 是 4.41.0 新增的独立请求模式，当前任务没有使用，
  迁移它不能解决 XEAPI 兼容问题；输。

## Consequences

- 新请求默认走上游 4.41.1 CSR；旧 BSR 只在发送业务请求前的握手阶段兜底。
- 2026-11-08 实测：CSR 用匿名会话请求
  `/api/vipnewcenter/app/level/task/list` 返回 HTTP 200、`code=200` 和
  `taskList`；显式 BSR 同样返回 200。真实登录态接口仍需账号验证。
- 新增配置不改变数据库、Compose 服务或环境变量；现有调用方无需修改。
- 完整离线套件 70 个 PHP 测试与 Node 前端交互测试通过。
