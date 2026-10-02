<?php
// 全局中间件定义文件
return [
    // 处理Pjax请求
    \app\middleware\CheckPjaxRequest::class,
    // 加载网站配置
    \app\middleware\LoadConfigs::class,
    // 整体模板切换（原版/若依）
    \app\middleware\SiteTemplate::class,
];
