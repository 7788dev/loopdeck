# B 站桌面客户端经验与积分协议核查

核查日期：2026-11-11。目标为用户提供的 Windows 客户端及授权测试账号。核查与实现基线为 `362f045`（v1.2.40）。

## 结论与范围

| 问题 | 结论 | 证据与边界 |
| --- | --- | --- |
| 当前每日经验是否少发 | 测试日最终到账 50，与每天投币 3 枚的配置一致 | 六笔账本：登录 5、观看 5、三次投币各 10、大会员 10；未调高投币数量 |
| 是否发现遗漏的免费等级任务 | 当前已核查入口未发现 | 桌面个人中心、会员中心权益目录和实际账本；不声称覆盖所有用户、活动、手机专属入口 |
| 是否存在可新增的功能 | 大会员积分签到及三种任务已接入，独立于等级经验 | 签到实得 5，福利及浏览任务实得 160；读回余额 165、三个任务 state=3 |
| 哪些过时协议应清理 | 主站分享、旧直播任务此前已删除，此次继续固定不可达并清理残留说明 | SDK/适配器方法、任务白名单、新装种子、界面与 `FeatureRemovalTest`；漫画和银瓜子兑换仍有有效响应，不误删 |

应用代码新增积分任务、收益明细和协议校验；取舍见[决策记录](../../.agents/notes/implemented/architecture/2026-11-11-bilibili-vip-points.md)，用户使用说明见[功能说明](../FEATURES.md)。

## 采集与可复查来源

使用 REA MCP 分析 `D:\Program Files\bilibili\resources\app.asar`，读取所选模块，并通过本机环回调试端口被动采集实际运行的渲染脚本。安装包为 1.17.9，运行环境为 Electron 22.3.27 / Chromium 108；运行页面使用 `https://bilipc.bilibili.com` 虚拟来源，和 ASAR 内脚本版本不同。

桌面 `Mine` 模块将会员入口链接到 [官方会员中心](https://account.bilibili.com/account/big)。会员中心二维码又链接 [大积分页](https://big.bilibili.com/mobile/bigPoint?navhide=1&closable=1)。本次新增积分协议来源是这个官方关联页面，不将它描述成桌面原生每日等级任务。

| 来源 | SHA-256 |
| --- | --- |
| `resources/app.asar` | `5240e1114453521a235e6f9e069707ef0361adc0aa739edc140c4b742ef54fc3` |
| 运行时 `Mine.056f43b4.js` | `f253d41a9d8e6a76db134efb0ba8b0a5f6bddc1b328c1d514e721fe1dbf4dd18` |
| 运行时 `index.cc8c2a37.js` | `26163884f17f3b1a58f41a70f2ba6d0d8bb7aa9f31535380b459080795f1b935` |
| 会员中心 `big.ef27017b354a3550689491a2af7018bdc353c5f7.js` | `0efd07d4e4c08175bc7b768e28b5b5daf51dcf9404f340a83cdae357f5e25034` |
| 会员中心 `2.big.ef27017b354a3550689491a2af7018bdc353c5f7.js` | `5fc1d09600366d33a9e9a3708e9af355a1797118b0de2dd578bbb3772dfc5dd6` |
| 积分页 `vip.04629c09eacfa5bc90a2c07c165454d5b5f52ff7.js` | `596b27af916248ac8c02fe0dd84f01ee89f642cfcaf3414d1a6d29d17dd96671` |
| 积分页 `7.vip.04629c09eacfa5bc90a2c07c165454d5b5f52ff7.js` | `887a892e271d2f8e0dc6f0bfaf0fec3977af1a969e58d2dab8a6f8957ac54bc8` |
| 积分页 `20.vip.04629c09eacfa5bc90a2c07c165454d5b5f52ff7.js` | `919462c2ed2974027e3a3231b179f488122d4c2bcb708ae9e58397eb49d0ee4b` |

`big` 文件来自 `https://s1.hdslb.com/bfs/static/jinkela/big/`；`vip` 文件来自 `https://s1.hdslb.com/bfs/static/jinkela/big-mobile/`。仓库不存客户端二进制、官方脚本、账号 Cookie、服务器密码或完整抓取日志。

REA 会话保留的证据：

- ASAR 定向分析：`ev_13a48b178577289156e924a15319b23175d09aefa88a0d275ade7cb143870077`。
- 运行时脚本采集：`ev_df75e7d25562194d447afcb5dbe42266930219717f32c914da20715399b2aafb`；72 份脚本导出：`ev_787f7c14a19456db1bfecec802c8142b2a2dad1cddc09822641ed06140a6aaf8`。
- 积分页四个选定脚本分析：`ev_9c0f7a31185a7ac07ad7035006f4416a4abbaedeb3ea84693edbc315f1be041f`。

完整 ASAR 分析在无关依赖处超时，之后改用定向模块。REA 返回的应用图/语义图是部分覆盖；包装调用的端点不一定被图识别，`sign2` 字符串图查询没有命中，不能据此声称接口不存在。协议细节由原脚本精确位置、调用封装及真实 HTTP 回查共同确认。这里的证据 ID 是该 REA 会话的索引，不是公开下载链接。

## 协议与状态

以下路径的主机均为 `https://api.bilibili.com`。CSRF 及 Cookie 只在请求体/头部。

| 功能 | 方法与路径 | 核查字段 |
| --- | --- | --- |
| 每日基础奖励 | GET `/x/member/web/exp/reward` | 明确布尔值 `login`、`watch` |
| 实时投币经验 | GET `/x/web-interface/coin/today/exp` | 数字 `data`，日上限 50 |
| 等级账本 | GET `/x/member/web/exp/log` | 历史核对，存在结算延迟，不作即时合计 |
| 当前会员权益 | GET `/x/vip/privilege/my` | `mid`、设备 `buvid`、平台；type 9 的 state 0/1/2 分别为待领/已领/需先观看 |
| 大会员每日经验 | POST `/x/vip/experience/add` | 表单 `mid`、`buvid`、`csrf`；`data.is_grant=true` 才确认发放，69198 为已领取 |
| 积分任务中心 | GET `/x/vip_point/task/combine` | `vip_info`、`point_info.point`、`task_info.modules`、`sing_task_item`、`exp_value` |
| 新版签到状态 | GET `/x/vip/vip_center/sign_in/three_days_sign` | `data.three_day_sign.signed`、签到奖励规则、`big_point.point` |
| 新版签到 | POST `/pgc/activity/score/task/sign2` | JSON `t`（毫秒）、`csrf`、`buvid` |
| 旧活动签到 | POST `/pgc/activity/score/task/sign` | 表单 `csrf`、`buvid`；以当日签到历史确认 |
| 积分任务完成 | POST `/pgc/activity/score/task/complete/v2` | 表单 `taskCode`、`csrf`、`buvid`；提交后以 state 3 确认 |

关键定位采用原压缩脚本第 1 行、从 0 开始的字符偏移：会员 `big` 入口的权益路径 26556、经验领取路径 27130；`2.big` 的按钮/账号限制约 159000–160700、领取处理约 161884；积分 `vip` 入口的 task combine 101187、complete 101396、sign2 102073；`7.vip` 的任务配置及完成/刷新过程约 11000–14300。

二轮复读纠正了平台字段：会员权益的 `platform` 来源是 `navigator.userAgentData.platform || navigator.platform`；固定 `pc` 属于权益目录 `/x/vip/privilege/v2/list`。SDK 按自己的 Windows UA 使用 `Windows`，没有把两个端点的参数混用。账号/设备字段缺失没有被证明是此次低经验的原因。

`exp_value=3` 是新版签到活动分组，不是等级经验数值。task state 0 是尚待领取，1 可执行，3 已完成。只接入观测到的三个完成动作：

- `bonus`：在期普通大会员 100 / 年度大会员 200，每人限领一次，升级后不补差额。
- `privilege`：浏览权益页，50，每人一次。
- `dress-view`：浏览装扮商城，10，每人一次。若账号仍为 state 0，报告需客户端领取；当前测试账号为 state 1。

当前 `ogvwatchnew` 规则明确要求大陆版手机 APP 连续观看 10 分钟，奖励 40 大积分；它不是普通等级经验。会员购计时、付费影片、商品/装扮消费和未知任务均不自动执行。

## 在线验证

等级经验第一次读取时仍有登录/观看未结算，后续账本却将奖励归到 00:40 的原提交时间。第二轮在 02:12 再次独立读取：总经验 11722，当天六笔合计 50，登录/观看为 true、投币经验 30、type 9 为已领取。不能把后续启动桌面客户端与到账时间接近当成因果证据。

积分验证先单独运行当前新版签到一次：signed false → true，余额 0 → 5。之后将工作区新 SDK 和工作流只加载到服务器独立 PHP 进程，没有覆盖生产代码或修改任务数据库：

| 检查 | 实际结果 |
| --- | --- |
| 三项任务执行前 | 余额 5，三项 state 1 / complete_times 0 |
| 通过新工作流执行 | 三次完成 POST 均 code 0 |
| 独立 GET 回查 | 余额 165，三项 state 3 / complete_times 1 |
| 同一工作流再次执行 | 0 次 POST，余额仍 165 |
| 第二轮独立进程 GET | 签到 true、余额 165、三项全部完成 |

漫画已有今日签到/分享响应；银瓜子兑换响应余额不足 403，不能将余额不足当作端点下线。没有在真实账号上进行新的投币、购买、权益兑换或手机观看任务。

## 修复验收与未覆盖项

- 官方字段缺失、空/未知会员权益错误处理在原代码复现失败，修改后同一测试通过；PHP 8.2.35 隔离容器再次重放相同的修复前失败/修复后通过。
- 新积分回归通过真实 HTTP transport、helper、executor、任务补种和隔离数据库主调度，覆盖延迟、去重、明确风控、设备预检未提交、暂时读失败、跨日、24 次上限和通知/日志状态。
- 明确风控 73319 终止本轮；设备预检失败与 POST 丢失响应分开记录，避免漏签或重发。未知状态与余额上涨本身不会被用作任务完成证据。
- 本地 PHP 8.3.33 全部 74 项离线回归及 Node 前端交互通过；PHP 8.2.35 隔离容器的六项 B 站回归和功能退役回归通过。桌面、390px 与 320px 的真实模板截图已检查。
- 旧签到活动分支、冻结/封禁、LV0 和延迟/断网场景通过原脚本与离线 fixture 验证，测试账号不具备这些现场条件。单账号成功不保证其他活动组、账号资格或未来服务端规则相同。
- 安装包和运行脚本的版本不同，会员官方资源也可独立更新；本次没有证明存在更多隐藏的免费等级经验，也没有声称逆向完全部客户端协议。
