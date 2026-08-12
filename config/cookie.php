<?php
// +----------------------------------------------------------------------
// | Cookie设置
// +----------------------------------------------------------------------
return [
    // cookie 保存时间
    'expire'    => 60 * 60 * 24,
    // cookie 保存路径
    'path'      => '/',
    // cookie 有效域名
    'domain'    => '',
    // cookie 启用安全传输（HTTPS 部署下自动开启，本地 HTTP 调试仍可用）
    'secure'    => ($_SERVER['HTTPS'] ?? '') === 'on'
        || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    // httponly设置：会话 cookie 不允许被 JavaScript 读取，避免 XSS 直接窃取会话
    'httponly'  => true,
    // 是否使用 setcookie
    'setcookie' => true,
    // samesite 设置，支持 'strict' 'lax'：阻断跨站请求携带会话
    'samesite'  => 'Lax',
];
