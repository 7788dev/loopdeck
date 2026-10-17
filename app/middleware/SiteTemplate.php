<?php

declare(strict_types=1);

namespace app\middleware;

use think\facade\View;

/** Always use the sole RuoYi view root, including legacy installations. */
final class SiteTemplate
{
    public function handle($request, \Closure $next)
    {
        $app = app();
        $application = $app->http->getName();
        if (in_array($application, ['index', 'admin'], true)) {
            View::engine()->config([
                'view_path' => $app->getBasePath()
                    . $application . DIRECTORY_SEPARATOR
                    . (string)config('view.view_dir_name', 'view') . DIRECTORY_SEPARATOR,
                // Compiled templates persist in the runtime volume across image
                // upgrades. Namespace them by the output filter so a config-only
                // escaping fix is never defeated by a stale compiled page.
                'cache_path' => $app->getRuntimePath() . 'temp' . DIRECTORY_SEPARATOR
                    . substr(hash('sha256', (string)config('view.default_filter', '')), 0, 12)
                    . DIRECTORY_SEPARATOR,
            ]);
        }

        return $next($request);
    }
}
