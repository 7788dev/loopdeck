# Agent Note: 整体模板与原版子模板分层

Status: implemented

## Problem

用户需要保留原版整站，并能选择另一套完整若依界面。现有首页与登录模板是局部选择，不能代表控制台和站长后台；仅保存局部模板 ID 而固定渲染入口页面也不能实现切换。

## Decision

`cloud_configs.site_template` 保存 `default` / `ruoyi`，未配置或值无效时使用原版。`SiteTemplate` 中间件在加载配置后选择 index/admin 的视图根目录，原版使用原目录，若依使用各自的 `view/ruoyi/`。每次请求都设置根目录，避免同一应用实例保留上次主题。

原版 `web.index_template` 和 `web.login_template` 保持独立存储，由 `SiteTheme::entry()` 通过后台已有模板清单校验后选择入口文件。若依忽略这两个局部选项，但不覆盖其值；切回原版继续使用原先选择。公开收款码页同样跟随整体模板。

采用若依经典版的 Bootstrap 3 资源、布局和皮肤，保留 LoopDeck 的控制器、权限、业务请求、任务机制与 PJAX。若依页面使用 DataTables Bootstrap 3 适配器；原版保持 Bootstrap 5。共享业务脚本只按当前模板选择表格类名，不依赖若依 Java 服务或演示模块。

## Alternatives considered

**整站直接替换为若依。** 无法保留原版和原有局部模板选择，不符合需求。

**接入若依 Vue / Java 后端。** 会引入第二套路由、认证与部署体系，超出新增整体模板的范围。

**仅覆盖原版 CSS。** 两代 Bootstrap 的网格、表单和插件接口不同，无法可靠覆盖登录、后台表格和弹窗。

**复制若依演示项目。** 带入无关功能和示例数据，增加维护成本。实际仅保留页面使用的基础资源和许可。

## Consequences

若依模板的官方版本对照、视觉取舍和 PJAX 样式边界见[视觉迁移记录](2026-10-03-ruoyi-visual-reference.md)。

两套业务视图需要同步维护；`SiteTemplateTest` 检查活动页面覆盖、路径白名单、往返切换及公开收款码渲染。`TemplateRenderingTest` 和 `FrontendInteractionTest.js` 同时覆盖两套模板及兑换码、任务配置等共用交互。运行截图使用隔离数据库，带上游账号详情的补充截图使用明确标记的离线数据，不把它们当作真实上游任务执行证明。
