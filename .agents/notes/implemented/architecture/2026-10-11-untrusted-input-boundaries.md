# Agent Note: 不受信任输入的四个边界收紧（Host 回显、富文本 scheme、安装器 AJAX、WAF 白名单）

Status: implemented

## Problem

Host 头、富文本 URL 属性、安装器请求标记、WAF 白名单都是外部可控输入，各自的旧判定都存在可利用或可投毒的缺口：

- `get_Domain` 在 `web.domain`/`web.domain2` 均未配置时信任任意 Host 并回显，密码重置邮件（`app/index/model/Users.php:124`）与二维码分享链接（`app/index/controller/Ajax.php:103`）可被 Host 头投毒。
- `safe_html` 用字面 `javascript:`/`vbscript:`/`data:` 匹配拦截 URL 属性，但浏览器解析前会解码 HTML 实体并剥掉 URL 内的控制字符，`javascript&#58;`、`jav\tascript:` 直接绕过。
- 安装器 `Request::isAjax()` 同时接受 `?_ajax=1` 查询标记，跨站表单 POST 无需任何头即可伪造 AJAX 入口。
- `webscan_white` 的白名单按 query-string 子串匹配（`stristr($url_var, $value)`），任何请求追加 `?s=/admin/ajax/data` 即关闭全部过滤规则。

## Decision

提交 `4806205`（fix: harden host trust, installer boundary and content filters）落地，四个边界全部由 `tests/SecurityHardeningTest.php` 以可执行断言钉死：

- `get_Domain` 只在 Host 为 loopback 或精确命中已注册域名时回显；否则回退到注册域名，两者皆无时落到 `127.0.0.1`。
- `safe_html` 对 URL 属性先按浏览器方式解码（`html_entity_decode` + 剥离 `\x00-\x20\x7f`）再判 scheme；危险属性整体移除，其余属性原样保留，最终仍经外层转义兜底。
- 安装器改用 `Request::isAjax(true)`（严格校验 `X-Requested-With` 头）并叠加 `is_cross_origin_request()` 拒绝跨源表单。
- `webscan_white` 把白名单值锚定到路由路径（`PATH_INFO` → `REQUEST_URI` path → `SCRIPT_NAME`），剥掉遗留 `s=` 前缀后按段边界匹配；`docker/nginx-security.conf` 确认 PATH_INFO 在实际部署中承载真实路由。

## Alternatives considered

**继续回显未注册的 Host。** 输了：Host 头攻击者可控，回显等于把外发链接交给攻击者重写；功能代价只落在未登记域名的部署上，属可接受的运维前提（登记域名即可恢复）。

**保留字面 scheme 匹配、只扩充正则。** 输了：编码变体枚举不完尽（实体、十六进制、控制字符拆分）；decode-then-escape 与浏览器渲染语义一致，且双重编码（`&amp;#106;`）浏览器只解码一次、本就无害，按浏览器语义判定后保留它才是正确行为。

**保留 `isAjax()` 宽松判定、只加 Origin 校验。** 输了：无 Origin/Referer 的请求会 fail-open；严格头校验对真实安装页（jQuery 自动附带该头）无影响，组合起来才闭合伪造路径。

**直接删除 360webscan。** 输了：它是部署内现存的纵深防线，删除的回归风险与论证成本大于按路径锚定修复。

## Addendum: 属性分隔符绕过（同日追加）

决策落地后复查发现 `safe_html` 的三条属性正则都要求属性名前是**空白符**，而 HTML5 分词器在两个额外位置同样会开启新属性：`/` 之后（`<a/onclick=…>`）以及**引号闭合后紧随的任意字符**（`<a href="…"onclick=…>`）。两者都能让事件处理器原样穿过清洗器；公告富文本经 `safe_html` 渲染给所有用户（`console/index.html`）。修复：属性起始分隔符放宽为 `[\s/"']`（lookbehind），URL scheme 的"按浏览器方式解码"判定不变；`tests/SecurityHardeningTest.php` 追加了分隔符绕过载荷与"合法链接不被误伤"的双向断言。

## Consequences

未在站点设置登记域名的部署，外发链接会指向回退地址（注册域名或 `127.0.0.1`）而非访问时的域名——升级说明需要提醒运维登记域名。买到的是：Host 投毒、scheme 编码绕过、伪造 AJAX 安装请求、WAF 白名单绕过四条路径全部关闭，且每个边界都有独立可重放的测试断言。
