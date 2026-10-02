<?php

declare(strict_types=1);

namespace app\middleware;

use think\facade\View;

/**
 * 整体模板切换：站点在后台「前台模板设置」选择整体模板后，
 * 若依模式下把视图根目录切到各应用的 view/ruoyi/，模板名保持不变。
 */
final class SiteTemplate
{
    public function handle($request, \Closure $next)
    {
        $app = app();
        $application = $app->http->getName();
        if (in_array($application, ['index', 'admin'], true)) {
            $theme = (string)config('sys.site_template', 'default') === 'ruoyi'
                ? 'ruoyi' . DIRECTORY_SEPARATOR : '';
            View::engine()->config([
                'view_path' => $app->getBasePath()
                    . $application . DIRECTORY_SEPARATOR
                    . (string)config('view.view_dir_name', 'view') . DIRECTORY_SEPARATOR
                    . $theme,
            ]);
        }

        return $next($request);
    }
}
