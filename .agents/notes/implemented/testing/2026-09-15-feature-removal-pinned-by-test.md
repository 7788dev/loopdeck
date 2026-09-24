# Agent Note: 移除的功能必须由 FeatureRemovalTest 钉住

Status: implemented

## Problem

v1.2.7 移除了分站(subsite)、代理(reseller)与抖音功能,更早移除了旧商品商店与 Bilibili 分享。删代码容易留下残渣:仍可访问的路由、仍能自动路由的 `*_Notify`/`*_Return` 回调、仍能发货的结算逻辑、仍能发卡的 Kms 方法——残渣既是漏洞也是回归隐患,仅靠 code review 无法保证删干净。

## Decision

约定:每次移除功能,必须在 [tests/FeatureRemovalTest.php](../../../../tests/FeatureRemovalTest.php) 补上"移除后不可达"的断言,并与删除代码同一次提交落地。该测试目前钉住:退役商店页面 404、控制器动作不存在、Epay 回调方法不可自动路由、`PaymentSettlement::SHOPS` 封闭为 `['vip','quota','money']`、退役商品无法下单/结算、代理发卡方法不存在、归档站点设置不可改。落地提交:`0605dd3`(2026-09-15)。

## Alternatives considered

**只删代码,靠人工 grep 检查残留。** 输因:ThinkPHP 的自动路由会让漏删的公共方法重新可达,人工检查没有回归信号,后续改动可能无声复活被删功能;断言式测试在离线套件里每次必跑,残留即失败。

## Consequences

买到的:功能移除有了永久回归闸门,随离线套件(`for test_file in tests/*Test.php; do php "$test_file"; done`)自动执行。付出的:`FeatureRemovalTest` 把支持的商品集合编码成硬断言,将来新增商品类型或功能必须显式更新该测试——这是有意的摩擦,防止集合被无声扩大。
