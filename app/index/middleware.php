<?php
// 全局中间件定义文件
return [
    // 处理Pjax请求
    \app\middleware\CheckPjaxRequest::class,
    // 加载网站配置
    \app\middleware\LoadConfigs::class,
];
