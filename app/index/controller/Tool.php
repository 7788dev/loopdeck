<?php

declare(strict_types=1);

namespace app\index\controller;

use Analyse\Video;
use think\facade\Request;

class Tool extends Common
{
    protected $middleware = [
        'app\middleware\CheckLoginUser',
        'app\middleware\CheckAjaxRequest',
    ];

    public function analyse()
    {
        return response('Not Found', 404);

        /* Free 功能区已停用
        $url = trim((string)Request::post('url', ''));
        if ($url === '') {
            return resultJson(0, '请先输入要解析的链接');
        }
        $result = (new Video())->analyse($url);
        return resultJson(
            (int)($result['code'] ?? 0),
            (string)($result['message'] ?? '解析失败'),
            (array)($result['data'] ?? [])
        );
        */
    }
}
