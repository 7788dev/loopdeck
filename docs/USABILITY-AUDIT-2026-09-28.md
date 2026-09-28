# 可用性审查与回归覆盖（2026-09-28）

本次检查从安装、前台入口、账号与任务、付款、个人中心、后台管理到更新器，逐项核对页面、控制器、模型和现有测试。修复以用户能够完成操作、失败时不误报成功、已有选择不被意外覆盖为准。

本文前半部保留发布时的审查历史；后续在线支付、余额与定价逻辑已删除，见[当前权益分配方式](../README.md#配额与兑换码)。

## 已修复的问题

| 场景 | 原来的实际问题 | 修复与验证 |
| --- | --- | --- |
| 余额充值 | 选择支付方式时读不到另一个函数内的金额变量；浏览器可能把同名 input 当作金额 | 在提交函数读取金额；金额框支持两位小数；执行前端函数验证请求内容 |
| 挂机时间 | 网易云、B站、小黑盒关闭弹窗后才读取时间，存在提交空值并关闭挂机的风险 | 关闭前读取；三个平台均有前端执行测试 |
| 任务高级配置 | 关闭弹窗后才序列化表单 | 表单仍存在时先序列化；测试音乐人、评分、听歌、直播间和投币配置 |
| 移动端时间弹窗 | 固定使用屏幕宽度的 20%，手机上输入和说明过窄 | 最大 420px，窄屏保留左右 16px；执行 375px 宽度测试并检查浏览器布局 |
| 任务开关 | 后端拒绝或网络失败后，页面仍显示刚切换的状态 | 请求期间禁用，失败恢复原值，显示错误；成功、拒绝、网络失败均测试 |
| 管理账号入口 | 网易云、小黑盒把账号 ID 拼在 URL 助手生成的 `.html` 后 | 使用完整的账号详情路径；渲染列表并检查真实链接 |
| 小黑盒列表 | 导入存储 `displayname`，列表读取 `nickname` | 对齐字段并提供默认昵称 |
| 收款二维码生成 | 二维码编码器类名大小写不符合 classmap；部分入口无法加载 | 统一 `netease\QRcode`；独立进程生成图片后再解码核对内容 |
| 收款二维码保存 | 账号配额不足时保存失败，接口仍返回生成成功 | 保留失败结果，不生成误导图片；使用真实 ORM 和临时数据库测试 |
| 收款人识别 | 不同用户可使用同名识别码，公共页面只按名字查询 | 拒绝已有重名；新链接带用户 ID；旧链接存在多个匹配时明确拒绝，避免选错收款人 |
| 微信收款码 | 合法 `wxp://` 内容被当作非法 URL 拒绝 | 仅微信二维码内容接受 wxp；跳转仍只接受 HTTP(S) |
| 二维码链接内容 | 微信参数未编码，生成入口又二次解码，`&`、`%2B` 等内容改变 | 外层参数编码一次，服务器不重复解码；生成后实际识别核对原文 |
| 收款码上传与重置 | 换图失败仍可能沿用旧链接；下载 PNG 命名为 JPG；重置后旧图仍展示 | 换图清空原值，忽略过时上传结果，重置清图，下载使用 `.png` |
| 公共收款页 | 依赖不存在且位于运行数据目录的图片 | 使用独立文字布局，不再依赖缺失图片 |
| 后台删除账号 | 按平台用户号删除，可能同时影响不同平台的同号账号 | 按账号记录主键删除，关联任务按用户和平台限定；清理日志与网易云本地状态 |
| 后台删除用户 | 用户消失后留下账号归属与任务，账号可能无法重新绑定 | 同一事务清理所属账号和任务；保留其他用户、主管理员及财务历史 |
| 后台编辑任务 | 修改名称或描述后重新启用用户已关闭的任务 | 保留用户开关状态，只按下架和会员限制禁用 |
| 余额购买和卡密 | 扣余额、兑换卡、增加权益跨多个独立写入，失败可能留下一半结果 | 使用事务并锁定用户；测试扣款后发放失败回滚、卡密防重放 |
| 支付回调 | 先把订单标为完成，再发放权益，发放失败后重试可能被认为已完成 | 锁定订单后在同一事务发放、记账和完成订单；测试失败回滚、成功重试、重复回调、签名篡改和商品不匹配 |
| 支付渠道 | 可以为已关闭或未配置的渠道创建订单 | 创建订单前检查渠道与商户配置，缺参返回清晰错误 |
| 自定义数据库前缀 | 生成卡密硬编码 `cloud_kms` | 使用配置前缀；全部数据库回归使用 `qa_` 前缀 |
| 个人资料 | 系统自动产生的 `QQ1234567890` 昵称超过编辑限制，用户无法原样保存 | 昵称允许 1–32 字；未修改返回正常反馈；拒绝与其他用户重复的找回邮箱 |
| 找回密码 | 邮件未发送或被限流也可能覆盖会话令牌；重置写入失败却消耗链接 | 发送成功才记录令牌；密码与链接消费放入事务；失败可重试，成功要求重新登录 |
| 修改密码 | 只删除当前浏览器会话，原会话标识未失效 | 用户和管理员修改密码时同时使旧会话标识失效 |
| 登录页脚本 | 公共脚本无条件初始化未加载的 PJAX / Clipboard 插件 | 按插件是否存在初始化，测试没有这些插件的登录页环境 |
| 后台清理日志 | 按钮调用的接口不存在，弹窗还会丢失输入 | 补齐有数量上限的接口，只清理最早的指定条数；测试保留最新记录 |
| 请求失败后重试 | 任务配置重复创建加载遮罩；听歌工具失败后按钮不再启用 | 统一由请求助手管理遮罩，失败恢复听歌按钮 |
| 网易云等级信息 | 获取失败后显示默认 0，用户误以为账号统计被清空 | 信息不可用时明确提示获取失败，仍允许修改任务配置 |

## 功能覆盖表

以下表格列出主要入口及对应的回归测试。一次通过代表测试中的输入、分支和断言通过，不等于验证了所有可能的第三方状态。

| 功能 | 回归覆盖 |
| --- | --- |
| 首次安装、管理员配置、数据库环境优先级 | `InstallDatabaseConfigTest`、`DatabaseInfoTest`、`TemplateRenderingTest` |
| Docker 配置生成、配置复用、外部数据库、缺失凭据保护 | `DockerDeploymentTest`、`DependencyIntegrityTest` |
| 更新器选源、版本匹配、自重启、回滚、后台立即检查 | `AutoUpdaterTest`、`AutoUpdaterSelfRestartTest`、`SystemUpdaterTest`、`UpdaterCheckRequestTest` |
| 首页、登录模板、帮助、后台页面、静态模板语法 | `TemplateCompilationTest`、`TemplateRenderingTest`、`FrontEndRepairTest`、`FaviconTest` |
| 注册、验证码拒绝、登录、退出、限流、个人资料、改密与找回 | `AuthenticationWorkflowTest`、`RequestBoundaryTest`、`SecurityHardeningTest` |
| 网易云扫码、账号、任务配置、听歌工具 | `LoginQrCodeTest`、`NeteaseSdkTest`、`NeteaseWorkflowTest`、`NeteaseListenToolTest`、`FrontendInteractionTest.js` |
| 网易云选歌、上报、累计量核验、重试、最终通知、创作者任务 | `NeteaseSongSelectionTest`、`NeteaseDakaScrobbleTest`、`NeteaseDakaSettlementTest`、`NeteaseSchedulerRetryTest`、`NeteaseFinalNotificationTest`、`NeteaseCreatorTasksTest`、`NeteaseNcblTest` |
| B站扫码、每日任务、投币、离线任务保护、日志 | `BilibiliSdkTest`、`BilibiliWorkflowTest`、`BilibiliTaskExecutorTest`、`LoginQrCodeTest` |
| 小黑盒凭据、签到响应、上游失败和失效 | `HeyboxWorkflowTest`、`CronControllerSafetyTest`、`FrontendInteractionTest.js` |
| Epic 游戏目录、周五计划、提醒队列、去重 | `EpicWorkflowTest`、`NotificationWorkflowTest`、`CronControllerSafetyTest` |
| 调度、领取执行权、多平台隔离、日志排序与结果文案 | `AutomaticScheduleTest`、`NeteaseScheduleTest`、`JobClaimingTest`、`ServiceIsolationTest`、`TaskLogsOrderingTest`、`TaskMessageTest` |
| Bark、PushPlus、WxPusher、邮件、每日汇总、失败隔离 | `BarkNotificationTest`、`NotificationWorkflowTest`、`NotificationTransportTest`、`NotificationFailureIsolationTest`、`MailTemplateTest`、`SmtpConnectionReuseTest` |
| 注册赠送、管理员配额、兑换码与已删除支付边界 | `QuotaWorkflowTest`、`FeatureRemovalTest`、`CommerceHardeningTest`、`SecurityHardeningTest` |
| 收款码导入、配额、重名、协议、生成与解码 | `AccountLifecycleTest`、`QrcodePayloadTest`、`TemplateRenderingTest` |
| 后台账号/用户删除、任务编辑、公告、模板设置、单站配置 | `AdminWorkflowTest`、`AccountLifecycleTest`、`AdminTemplateSettingsTest`、`SingleSiteConfigTest` |
| 已下架功能不可达 | `FeatureRemovalTest` |

## 执行方式与范围

- 52 个 `*Test.php`，以及 `node tests/FrontendInteractionTest.js`。
- 编译并检查 74 个未下架模板文件；实际渲染 64 个模板，另加 7 个账号列表/详情有数据场景，共 71 个场景。`TemplateRenderingTest` 同时调用 Node 检查渲染后的脚本语法。
- 新增的数据库测试使用 SQLite 内存库和实际 Think ORM，不读取 `.env`、`config/Db.php`，不连接站点数据库。测试涵盖状态更新、事务回滚和重复提交；SQLite 不模拟 MySQL 并发锁调度。
- Docker 构建的 dependencies 阶段提供 `pdo_sqlite` 和 Node，运行以上测试；这两个测试依赖不加入最终运行阶段。
- 本地 Windows：启用 PHP 的 `pdo_sqlite`，并把 `LOOPDECK_TEST_SHELL` 指向实际的 Git `bin/sh.exe`。例如每个测试使用 `php -d extension=pdo_sqlite tests/QuotaWorkflowTest.php`。需要 Node 在 PATH 中。
- 浏览器使用临时、无真实凭据的模板预览，检查了账号页面和时间弹窗布局；前端保存及错误回退以可重复执行的 JS 测试为准。

## 实测边界

本机没有 Docker CLI，本次部署回归执行的是仓库已有的 stub Docker 场景。真实 MySQL 首次建库、宿主机端口、服务器镜像代理和旧站升级需要在目标服务器验证，不能用离线测试冒充成功部署。

未提供网易云/B站/小黑盒有效会话，也没有真实支付商户和推送收件人授权。本次没有调用这些账号的实际任务、扣币、付款或对外发送通知。平台请求与返回分支由 fixture 覆盖，上游账号资格、风控、奖励入账和消息最终送达仍需对应账号的显式在线测试。

网易云密码登录和已经下架的 B站任务保持原有维护/停用状态，未将无有效协议的功能假装恢复。后台更新需要 updater 自身支持“立即检查”，普通代码与镜像更新无需修改 Compose 或数据库结构。
