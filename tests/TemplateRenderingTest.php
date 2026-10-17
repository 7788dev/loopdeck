<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

define('PJAX', false);
// Health probes and non-browser clients need not send User-Agent.
unset($_SERVER['HTTP_USER_AGENT']);
$_GET['token'] = str_repeat('a', 48);
$root = dirname(__DIR__);
$cachePath = sys_get_temp_dir() . '/loopdeck-render-' . bin2hex(random_bytes(6)) . '/';
mkdir($cachePath);
$viewConfig = require $root . '/config/view.php';
unset($viewConfig['type']);
$templateConfig = static function (string $viewPath) use ($viewConfig, $cachePath): array {
    return array_replace($viewConfig, ['view_path' => $viewPath, 'cache_path' => $cachePath]);
};
$app->config->set(['webname' => 'LoopDeck', 'title' => '测试站点', 'web_id' => 1, 'user_qq' => '10000', 'index_template' => 'default', 'login_template' => 'default'], 'web');
think\facade\Session::set('user.nickname', '测试用户');
think\facade\Session::set('user.qq', '10000');
$variables = [
    'homeBackground' => app\service\SiteTheme::homeBackground(),
    'redemption_presets' => app\service\RedemptionPlan::presets(),
    'redemption_max_days' => app\service\RedemptionPlan::MAX_DAYS,
    'redemption_max_accounts' => app\service\RedemptionPlan::MAX_ACCOUNTS,
    'redemption_max_batch' => app\service\RedemptionPlan::MAX_BATCH,
    'webTitle' => '测试', 'mail' => 'test@example.com', 'users' => [], 'list' => [], 'notice' => [], 'notices' => [],
    'timeCount' => 1, 'userCount' => 1, 'quota_used' => 0, 'user_count' => 1, 'account_count' => 0, 'job_count' => 0, 'execute_count' => 0,
    'accounts' => [], 'daily_limit' => 10, 'task_rows' => [], 'timing' => '', 'signature' => '', 'info_warning' => '', 'info_available' => false, 'level_info' => null,
    'data' => ['user_id' => '42', 'state' => 1, 'timing' => '', 'uid' => 1],
    'a_data' => ['user_id' => '42', 'mid' => '42', 'nickname' => '测试用户', 'avatar' => ''],
    'details' => ['level_now' => 1, 'level_next' => 2, 'listenSongs' => 0, 'listennum' => 0, 'loginnum' => 0],
    'epic_enabled' => false, 'epic_next' => '', 'notification' => app\service\UserNotificationSettings::redact(app\service\UserNotificationSettings::defaults()),
    'notification_error' => '', 'email_available' => false, 'deliveries' => [], 'email_ready' => false, 'email_enabled' => false, 'email_password_configured' => false,
    'notification_email_available' => false, 'notification_can_epic' => false, 'notification_deliveries' => [],
    'database_auto_configured' => true, 'install_token_required' => false, 'name' => '测试收款人', 'type' => 'wechat', 'url' => 'wxp://fixture',
] + (new app\service\SystemUpdater(sys_get_temp_dir() . '/loopdeck-render-no-updater.json'))->status();
$count = 0;
$failures = [];
$renderedPages = [];
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    foreach (['index', 'admin', 'install'] as $module) {
        $viewPath = $root . '/app/' . $module . '/view/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewPath)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewPath)));
            if ($file->getExtension() !== 'html' || str_starts_with($relative, 'common/')
                || str_ends_with($relative, '/head.html')) continue;
            $engine = new think\Template($templateConfig($viewPath));
            ob_start();
            try {
                $engine->fetch($file->getPathname(), $variables);
                $html = ob_get_contents();
                functionalCheck(strlen($html) > 100, 'Empty page');
                if ($module === 'index' && $relative === 'console/shop/card.html') {
                    functionalCheck(str_contains($html, '例如总数 7 个，可绑定 2 个哔哩哔哩 + 5 个网易云，也可全部绑定网易云。'), 'Account total usage example missing');
                    functionalCheck(!preg_match('/时长为 0|总数为 0|续期不叠加|旧版配额码/u', $html), 'Internal entitlement rules leaked into user copy');
                }
                if ($module === 'index' && $relative === 'console/epic/weeklyGame.html') {
                    functionalCheck(str_contains($html, '暂未获取到周免活动信息'), 'Empty Epic catalog lost its explanation');
                }
                $renderedPages[$module . '/' . $relative] = $html;
                $count++;
            } catch (Throwable $error) {
                $failures[] = $module . '/' . $relative . ': ' . $error->getMessage();
            } finally {
                ob_end_clean();
            }
        }
    }
    $accountData = ['avatar' => '', 'nickname' => '测试用户', 'displayname' => '测试用户', 'user_id' => '42',
        'name' => '测试收款码', 'alipay_url' => 'https://pay.example/a', 'qq_url' => 'https://pay.example/q', 'wechat_url' => 'wxp://fixture'];
    $account = ['id' => 1, 'uid' => 1, 'user_id' => '42', 'mid' => '42', 'nickname' => '测试用户', 'avatar' => '',
        'state' => 1, 'timing' => '', 'addtime' => '2026-09-28 08:00:00', 'data' => serialize($accountData)];
    $task = ['icon' => 'fa-check', 'name' => '签到', 'describe' => '每日签到', 'more' => true, 'execute_name' => 'sign',
        'config' => '{}', 'config_json' => '{"text":"quoted\'value"}', 'last_execute' => '', 'next_execute' => 0, 'job_state' => 0,
        'user_id' => '42', 'is_global' => false, 'offline' => false, 'offline_reason' => ''];
    foreach ([''] as $themePath) {
    $populatedViewPath = $root . '/app/index/view/' . $themePath;
    foreach (['netease', 'bilibili', 'qrcode'] as $platform) {
        foreach ($platform === 'qrcode' ? ['list'] : ['list', 'info'] as $page) {
            $relative = 'console/' . $platform . '/' . $page . '.html';
            $engine = new think\Template($templateConfig($populatedViewPath));
            ob_start();
            try {
                $taskRows = [$task];
                if ($platform === 'netease' && $page === 'info') {
                    $taskRows[] = array_replace($task, ['execute_name' => 'daka_new', 'name' => '每日300首',
                        'progress' => '已确认 144/300 首，正在自动补齐与核验（本轮截止 15:30）']);
                }
                if ($platform === 'bilibili') {
                    $taskRows[] = array_replace($task, ['execute_name' => 'globalroom', 'name' => '旧直播间配置',
                        'is_global' => true, 'offline' => true, 'offline_reason' => '旧直播任务已停用']);
                }
                $engine->fetch($populatedViewPath . $relative, array_replace($variables, ['list' => [$account], 'task_rows' => $taskRows]));
                $html = ob_get_contents();
                if ($platform === 'netease' && $page === 'info') {
                    functionalCheck(str_contains($html, '已确认 144/300 首') && str_contains($html, '本轮截止 15:30'),
                        'Listening progress did not render in the actual account page');
                }
                if ($platform === 'bilibili' && $page === 'info') {
                    if (str_contains($html, 'data-execute-name="globalroom"')
                        || !str_contains($html, 'disabled title="旧直播任务已停用"')) {
                        throw new RuntimeException('Retired global room configuration remained editable');
                    }
                }
                $renderedPages['populated/' . $themePath . $relative] = $html;
                if ($page === 'list' && $platform !== 'qrcode') {
                    functionalCheck(str_contains($html, '/index/console/' . $platform . '/info/42'), 'Account management link is not usable');
                }
                $count++;
            } catch (Throwable $error) {
                $failures[] = 'populated/' . $themePath . $relative . ': ' . $error->getMessage();
            } finally {
                ob_end_clean();
            }
        }
    }
    // Unequal card heights must not let Bootstrap floats place the next pair
    // beside the shorter card. Each desktop pair needs its own clearing row.
    foreach ([1, 4, 5] as $gameCount) {
        $games = [];
        for ($index = 1; $index <= $gameCount; $index++) {
            $games[] = [
                'title' => '测试游戏 ' . $index,
                'description' => str_repeat('长短不同的游戏介绍。', $index % 2 === 1 ? 16 : 1),
                'productUrl' => 'https://store.epicgames.com/p/layout-fixture-' . $index,
                'image' => $index === 5 ? '' : 'data:image/svg+xml;base64,' . base64_encode(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080"><rect width="1920" height="1080" fill="#23405a"/></svg>'
                ),
                'available' => $index <= 2,
                'start_at' => 1790870400, 'end_at' => 1791475200,
            ];
        }
        $engine = new think\Template($templateConfig($populatedViewPath));
        ob_start();
        try {
            $engine->fetch('console/epic/weeklyGame', array_replace($variables, ['list' => $games]));
            $html = (string)ob_get_contents();
            $document = new DOMDocument();
            $previousErrors = libxml_use_internal_errors(true);
            try {
                $document->loadHTML($html);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
            $xpath = new DOMXPath($document);
            $cardSelector = 'a[starts-with(@href, "https://store.epicgames.com/p/layout-fixture-")]';
            $rows = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " row ")][div/' . $cardSelector . ']');
            functionalCheck($rows->length === (int)ceil($gameCount / 2), 'Epic cards with unequal heights share an uncleared row');
            $seen = [];
            foreach ($rows as $rowIndex => $row) {
                $cards = $xpath->query('./div/' . $cardSelector, $row);
                functionalCheck($cards->length === min(2, $gameCount - $rowIndex * 2), 'Epic desktop row has the wrong number of cards');
                foreach ($cards as $card) {
                    $seen[] = $card->getAttribute('href');
                    functionalCheck(str_contains(' ' . $card->parentNode->getAttribute('class') . ' ', ' col-md-6 '),
                        'Epic cards lost the mobile single-column / desktop two-column breakpoint');
                }
            }
            functionalCheck($seen === array_column($games, 'productUrl'), 'Epic rows lost or reordered games');
            $renderedPages['populated/console/epic/weeklyGame-' . $gameCount . '.html'] = $html;
            $count++;
        } finally {
            ob_end_clean();
        }
    }
    functionalCheck(($viewConfig['default_filter'] ?? '') === 'html_escape',
        'Template output is not globally HTML-escaped');
    $payload = '<img src=x onerror=alert(1)>';
    $attributePayload = '"><img src=x onerror=alert(1)>';
    $jsBreakout = '1};alert(1);//';
    $render = static function (string $module, string $relative, array $vars) use ($root, $templateConfig): string {
        $engine = new think\Template($templateConfig($root . '/app/' . $module . '/view/'));
        ob_start();
        try {
            $engine->fetch($root . '/app/' . $module . '/view/' . $relative, $vars);
            $html = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }
        functionalCheck(strlen($html) > 100, 'Escaping fixture did not render: ' . $module . '/' . $relative);
        return $html;
    };
    $assertEncoded = static function (string $label, string $html, array $values): void {
        foreach ($values as $value) {
            functionalCheck(!str_contains($html, $value), $label . ': raw value escaped into markup');
            functionalCheck(str_contains($html, html_escape($value)), $label . ': value was not HTML-encoded');
        }
    };

    $publicQr = $render('index', 'index/qrcode.html', array_replace($variables, [
        'name' => $payload, 'type' => 'qq', 'url' => 'https://pay.example/qr',
    ]));
    $assertEncoded('Public payment page', $publicQr, [$payload]);

    $qrList = $render('index', 'console/qrcode/list.html', array_replace($variables, [
        'list' => [[
            'data' => serialize(['name' => $payload, 'alipay_url' => $attributePayload,
                'qq_url' => 'https://pay.example/q', 'wechat_url' => 'wxp://fixture']),
            'user_id' => $payload, 'addtime' => '2026-10-17 10:00:00', 'uid' => 1,
        ]],
    ]));
    $assertEncoded('Payment code list', $qrList, [$payload, $attributePayload]);

    $maliciousTask = array_replace($task, [
        'name' => $payload, 'describe' => $attributePayload, 'progress' => $payload,
        'config' => '{}', 'config_json' => '{}',
    ]);
    foreach ([
        ['bilibili', 'list', ['list' => [array_replace($account, [
            'nickname' => $payload, 'avatar' => $attributePayload, 'mid' => $jsBreakout,
        ])]]],
        ['netease', 'list', ['list' => [array_replace($account, [
            'user_id' => $jsBreakout,
            'data' => serialize(array_replace($accountData, [
                'nickname' => $payload, 'avatar' => $attributePayload, 'user_id' => $jsBreakout,
            ])),
        ])]]],
        ['bilibili', 'info', ['a_data' => array_replace($variables['a_data'], [
            'nickname' => $payload, 'avatar' => $attributePayload, 'mid' => $jsBreakout,
        ]), 'info_warning' => $payload, 'task_rows' => [$maliciousTask]]],
        ['netease', 'info', ['a_data' => array_replace($variables['a_data'], [
            'nickname' => $payload, 'avatar' => $attributePayload, 'user_id' => $jsBreakout,
        ]), 'signature' => $payload, 'task_rows' => [$maliciousTask],
            'data' => array_replace($variables['data'], ['user_id' => $jsBreakout])]],
    ] as [$platform, $page, $overrides]) {
        $html = $render('index', 'console/' . $platform . '/' . $page . '.html', array_replace($variables, $overrides));
        $assertEncoded($platform . ' ' . $page, $html, [$payload, $attributePayload]);
        functionalCheck(!str_contains($html, 'onclick="ajax_' . $platform . '_delete'),
            $platform . ' ' . $page . ': dynamic action was interpolated into inline JavaScript');
        if ($page === 'list') {
            functionalCheck(str_contains($html, 'data-' . $platform . '-delete='),
                $platform . ' list: delete action lost its data attribute');
        } else {
            functionalCheck(str_contains($html, 'data-task-toggle=') && str_contains($html, 'data-config-action'),
                $platform . ' info: task controls lost their data attributes');
            functionalCheck(str_contains($html, 'user_id: ' . js_string($jsBreakout)),
                $platform . ' info: account id was not encoded as a JavaScript string');
            functionalCheck(!str_contains($html, 'user_id: ' . $jsBreakout),
                $platform . ' info: account id reached a JavaScript object unquoted');
        }
    }

    $notification = $variables['notification'];
    $notification['email_address'] = $attributePayload;
    $notificationHtml = $render('index', 'console/user/notification.html', array_replace($variables, [
        'notification' => $notification,
        'notification_error' => $payload,
        'notification_email_available' => true,
        'notification_deliveries' => [[
            'channel' => $payload, 'title' => $payload, 'last_error' => $attributePayload,
            'status' => 2, 'attempts' => 1, 'created_at' => time(),
        ]],
    ]));
    $assertEncoded('Notification delivery log', $notificationHtml, [$payload, $attributePayload]);

    foreach (['index', 'admin'] as $module) {
        $alertHtml = $render($module, 'common/alert.html', [
            'msg' => $payload, 'url' => $attributePayload,
        ]);
        $assertEncoded(ucfirst($module) . ' alert page', $alertHtml, [$payload, $attributePayload]);
    }

    $epicHtml = $render('index', 'console/epic/weeklyGame.html', array_replace($variables, ['list' => [[
        'title' => $payload, 'description' => $attributePayload, 'productUrl' => 'javascript:alert(1)',
        'image' => '', 'available' => true, 'start_at' => time(), 'end_at' => time() + 60,
    ]]]));
    $assertEncoded('Epic weekly games', $epicHtml, [$payload, $attributePayload]);
    functionalCheck(!str_contains($epicHtml, 'javascript:alert(1)'),
        'Epic page followed an unsafe product URL');

    $previousToken = $_GET['token'] ?? null;
    $_GET['token'] = $jsBreakout;
    try {
        $resetHtml = $render('index', 'login/reset.html', array_replace($variables, ['mail' => $attributePayload]));
        functionalCheck(str_contains($resetHtml, 'mail:' . js_string($attributePayload)),
            'Reset page did not JavaScript-encode the mail value');
        functionalCheck(str_contains($resetHtml, 'token:' . js_string($jsBreakout)),
            'Reset page did not JavaScript-encode the token');
        functionalCheck(!str_contains($resetHtml, 'mail:"' . $attributePayload)
            && !str_contains($resetHtml, 'token:"' . $jsBreakout),
            'Reset page interpolated a value into a raw JavaScript string');
    } finally {
        if ($previousToken === null) {
            unset($_GET['token']);
        } else {
            $_GET['token'] = $previousToken;
        }
    }

    $savedWeb = $app->config->get('web');
    $app->config->set([
        'webname' => $payload, 'title' => $payload,
        'keywords' => $attributePayload, 'description' => $attributePayload,
        'icp' => $payload, 'user_qq' => $attributePayload,
    ], 'web');
    $homeHtml = $render('index', 'index/index.html', $variables);
    $assertEncoded('Homepage site settings', $homeHtml, [$payload, $attributePayload]);
    $settingsHtml = $render('admin', 'system/set/info.html', $variables);
    $assertEncoded('Admin site settings form', $settingsHtml, [$payload, $attributePayload]);
    $app->config->set($savedWeb, 'web');

    foreach (['index', 'admin', 'install'] as $module) {
        $viewRoot = $root . '/app/' . $module . '/view/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot)) as $file) {
            if ($file->getExtension() !== 'html') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            $label = $module . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot)));
            functionalCheck(!preg_match('/\son[a-z]+\s*=\s*(["\'])[^"\']*(?:\{\$|\{:\$)/i', $source),
                $label . ': inline event handler interpolates template output');
            functionalCheck(!preg_match('/javascript:[^"\']*(?:\{\$|\{:\$)/i', $source),
                $label . ': javascript: link interpolates template output');
            functionalCheck(!preg_match('/<script\b[^>]*>.*?\{\$.*?<\/script>/is', $source),
                $label . ': script block interpolates an auto-escaped variable');
            functionalCheck(!str_contains($source, '{:config('),
                $label . ': raw config output bypasses HTML escaping');
        }
    }

    $savedUser = think\facade\Session::get('user');
    think\facade\Session::set('user.quota', app\service\RedemptionPlan::UNLIMITED_ACCOUNTS);
    think\facade\Session::set('user.vip_start', date('Y-m-d'));
    think\facade\Session::set('user.vip_end', app\service\RedemptionPlan::PERMANENT_VIP_END);
    foreach (['console/index.html', 'console/shop/card.html'] as $relative) {
        $engine = new think\Template($templateConfig($populatedViewPath));
        ob_start();
        try {
            $engine->fetch($populatedViewPath . $relative, $variables);
            $html = ob_get_contents();
            functionalCheck(str_contains($html, '永久会员') && str_contains($html, '账号总数：不限'), 'Unlimited account display missing');
            functionalCheck(!str_contains($html, '9999-12-31') && !str_contains($html, '-1 个'), 'Storage sentinels leaked into the page');
            $renderedPages['permanent/' . $themePath . $relative] = $html;
            $count++;
        } finally {
            ob_end_clean();
        }
    }
    think\facade\Session::set('user', $savedUser);
    }
} finally {
    restore_error_handler();
    foreach (glob($cachePath . '*') as $file) unlink($file);
    rmdir($cachePath);
}
functionalCheck($failures === [], implode("\n", $failures));
// Optional local browser preview, containing only the inert fixtures above.
$previewPath = getenv('LOOPDECK_PREVIEW_DIR');
if ($previewPath && is_dir($previewPath)) {
    foreach ($renderedPages as $name => $html) {
        file_put_contents($previewPath . '/' . str_replace('/', '-', $name), $html);
    }
}
$scriptInput = tempnam(sys_get_temp_dir(), 'loopdeck-scripts-');
try {
    file_put_contents($scriptInput, json_encode($renderedPages, JSON_THROW_ON_ERROR));
    $process = proc_open(['node', __DIR__ . '/RenderedJavascriptCheck.js', $scriptInput], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    functionalCheck(proc_close($process) === 0, $output);
} finally {
    unlink($scriptInput);
}
echo "Rendered {$count} templates without warnings\n";
