<?php

declare(strict_types=1);

/**
 * Renders every check-in platform page (add, list, info, profile partial) and
 * the sidebar with representative data, so template syntax errors, missing
 * variables, escaping mistakes and credential leaks fail offline.
 */

use app\service\PlatformRegistry;
use think\facade\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkinPagesCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
if (!defined('PJAX')) {
    define('PJAX', true);
}
if (!defined('WEB_ID')) {
    define('WEB_ID', 1);
}
$app = new think\App($root);
$app->initialize();
Config::set(['webname' => 'LoopDeck', 'title' => 'Test'], 'web');
$cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loopdeck-checkin-pages-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
checkinPagesCheck(mkdir($cachePath, 0700, true), 'Unable to create template test cache');
$engine = new think\Template(['view_path' => $root . '/app/index/view/', 'cache_path' => $cachePath]);

$render = static function (string $template, array $data) use ($engine): string {
    ob_start();
    try {
        $engine->fetch($template, $data);
        return (string)ob_get_contents();
    } finally {
        ob_end_clean();
    }
};

$secret = 'SECRET-CREDENTIAL-VALUE';
$hostile = '<script>alert(1)</script>';
$summaries = [
    'tieba' => ['date' => date('Y-m-d'), 'updated_at' => time(), 'success' => false, 'in_progress' => true,
        'message' => '签到进度=已签=3/5', 'total' => 5, 'done' => 3, 'skipped' => 1, 'failed' => 1, 'pending' => 0,
        'bonus' => 12, 'failed_names' => [$hostile], 'skipped_names' => ['已关闭']],
    'quark' => ['date' => date('Y-m-d'), 'updated_at' => time(), 'success' => true, 'signed_today' => true,
        'sign_progress' => 3, 'sign_target' => 7, 'sign_reward' => 20 * 1048576, 'reward' => 20 * 1048576],
    'tianyi' => ['date' => date('Y-m-d'), 'updated_at' => time(), 'success' => true, 'signed_before' => false,
        'bonus_mb' => 50, 'capacity_total' => 10, 'capacity_used' => 5],
    'aliyundrive' => ['date' => date('Y-m-d'), 'updated_at' => time(), 'success' => true, 'month' => date('Y-m'),
        'count' => 3, 'unclaimed' => 1, 'track' => [
            ['day' => 1, 'signed' => true, 'missed' => false, 'claimed' => true, 'reward' => '50GB'],
            ['day' => 2, 'signed' => false, 'missed' => true, 'claimed' => false, 'reward' => ''],
            ['day' => 3, 'signed' => true, 'missed' => false, 'claimed' => false, 'reward' => $hostile],
        ]],
];
$stats = [
    'tieba' => [],
    'quark' => ['capacity_total' => 10 * 1073741824, 'capacity_used' => 3 * 1073741824, 'sign_progress' => 3, 'sign_target' => 7],
    'tianyi' => ['capacity_total' => 2 * 1099511627776, 'capacity_used' => 1099511627776,
        'family_total' => 1099511627776, 'family_used' => 0],
    'aliyundrive' => ['capacity_total' => 100 * 1073741824, 'capacity_used' => 95 * 1073741824],
];

foreach (PlatformRegistry::types() as $type) {
    $platform = PlatformRegistry::get($type);
    $account = ['user_id' => 'abc_123', 'nickname' => $hostile, 'avatar' => '', 'state' => 1, 'timing' => '08:30',
        'addtime' => '2026-09-01 10:00:00'];
    $snapshot = ['type' => $type, 'user_id' => 'abc_123', 'rows' => [['label' => '登录状态', 'value' => $hostile]],
        'stats' => $stats[$type], 'signature' => '', 'updated_at' => time(), 'warning' => '', 'account_invalid' => false,
        'retry_at' => 0, 'stale' => false];
    $taskRows = [['icon' => $platform['icon'], 'name' => $platform['task'], 'describe' => $platform['description'],
        'execute_name' => PlatformRegistry::DAILY_TASK, 'last_execute' => '尚未执行', 'next_execute' => '09-25 08:30',
        'job_state' => 1]];

    foreach ([null, $account] as $current) {
        $html = $render("console/{$type}/add", ['platform' => $platform, 'current' => $current]);
        checkinPagesCheck(str_contains($html, 'class="js-checkin-form" data-type="' . $type . '"'), "{$type} add form missing");
        checkinPagesCheck(str_contains($html, 'checkin_console.js'), "{$type} add page lacks its script");
        checkinPagesCheck(str_contains($html, 'bg-gd-' . $platform['theme']), "{$type} add page lost its theme");
        foreach ($platform['fields'] as $field) {
            checkinPagesCheck(str_contains($html, 'name="' . $field['name'] . '"'), "{$type} add form lacks {$field['name']}");
        }
        checkinPagesCheck(!str_contains($html, $hostile), "{$type} add page did not escape the nickname");
        checkinPagesCheck(($current !== null) === str_contains($html, 'data-expected="abc_123"'), "{$type} update mode is wrong");
    }

    $html = $render("console/{$type}/list", ['platform' => $platform, 'list' => [], 'total' => 0, 'page' => 1,
        'pages' => 1, 'prev_page' => 1, 'next_page' => 1, 'page_numbers' => [1]]);
    checkinPagesCheck(str_contains($html, '还没有添加' . $platform['name'] . '账号'), "{$type} empty list state missing");
    $html = $render("console/{$type}/list", ['platform' => $platform, 'list' => [$account, ['state' => 0] + $account],
        'total' => 30, 'page' => 2, 'pages' => 3, 'prev_page' => 1, 'next_page' => 3, 'page_numbers' => [1, 2, 3]]);
    checkinPagesCheck(substr_count($html, 'js-checkin-delete') === 2, "{$type} list cards missing");
    checkinPagesCheck(str_contains($html, '登录已失效') && str_contains($html, '每日 08:30 签到'), "{$type} list states missing");
    checkinPagesCheck(str_contains($html, "/index/console/{$type}/list?page=3"), "{$type} list pagination missing");
    checkinPagesCheck(!str_contains($html, $hostile), "{$type} list did not escape the nickname");

    foreach ([$summaries[$type], []] as $summary) {
        $html = $render("console/{$type}/info", ['platform' => $platform, 'account' => $account, 'snapshot' => $snapshot,
            'summary' => $summary, 'task_rows' => $taskRows]);
        checkinPagesCheck(str_contains($html, 'id="task-logs-list" data-type="' . $type . '" data-user_id="abc_123"'), "{$type} log table missing");
        checkinPagesCheck(str_contains($html, 'class="form-check-input js-checkin-toggle"') && str_contains($html, 'checked'), "{$type} task switch missing");
        checkinPagesCheck(str_contains($html, 'account-profile" data-type="' . $type . '"'), "{$type} profile partial missing");
        checkinPagesCheck(str_contains($html, 'task_logs_datatables.js') && str_contains($html, 'checkin_console.js'), "{$type} info scripts missing");
        checkinPagesCheck(!str_contains($html, $hostile), "{$type} info page did not escape upstream text");
        checkinPagesCheck(!str_contains($html, $secret), "{$type} info page leaked a credential");
    }

    $html = $render("console/{$type}/profile", ['snapshot' => ['stale' => true] + $snapshot, 'platform' => $platform]);
    checkinPagesCheck(str_contains($html, 'data-stale="1"') && str_contains($html, 'js-refresh-profile'), "{$type} profile refresh hook missing");
}

// Platform-specific features.
$html = $render('console/tieba/info', ['platform' => PlatformRegistry::get('tieba'), 'account' => $account,
    'snapshot' => $snapshot, 'summary' => $summaries['tieba'], 'task_rows' => $taskRows]);
checkinPagesCheck(str_contains($html, '签到失败的贴吧') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;吧'), 'Tieba failed forums are not listed');
checkinPagesCheck(str_contains($html, '+12') && str_contains($html, 'width: 80%'), 'Tieba sign-in report is wrong');

$quarkSnapshot = ['type' => 'quark', 'stats' => $stats['quark']] + $snapshot;
$html = $render('console/quark/profile', ['snapshot' => $quarkSnapshot, 'platform' => PlatformRegistry::get('quark')]);
checkinPagesCheck(str_contains($html, '已用 3 GB') && str_contains($html, '共 10 GB') && substr_count($html, 'bg-elegance"') === 4,
    'Quark capacity or streak bar is wrong');

$tianyiSnapshot = ['type' => 'tianyi', 'stats' => $stats['tianyi']] + $snapshot;
$html = $render('console/tianyi/profile', ['snapshot' => $tianyiSnapshot, 'platform' => PlatformRegistry::get('tianyi')]);
checkinPagesCheck(str_contains($html, '个人云') && str_contains($html, '家庭云') && str_contains($html, '1 TB / 2 TB'), 'Tianyi capacity bars missing');

$html = $render('console/aliyundrive/info', ['platform' => PlatformRegistry::get('aliyundrive'), 'account' => $account,
    'snapshot' => $snapshot, 'summary' => $summaries['aliyundrive'], 'task_rows' => $taskRows]);
checkinPagesCheck(substr_count($html, 'class="checkin-day ') === 3 && str_contains($html, 'title="第 2 天 &middot; 漏签"'), 'Aliyun calendar missing');
$html = $render('console/aliyundrive/profile', ['snapshot' => ['stats' => $stats['aliyundrive']] + $snapshot,
    'platform' => PlatformRegistry::get('aliyundrive')]);
checkinPagesCheck(str_contains($html, '95%'), 'Aliyun capacity figure missing');

$sidebar = $render('console/head', []);
foreach (PlatformRegistry::platforms() as $type => $platform) {
    checkinPagesCheck(str_contains($sidebar, 'href="/index/console/' . $type . '/add"')
        && str_contains($sidebar, 'href="/index/console/' . $type . '/list"')
        && str_contains($sidebar, $platform['name']), "Sidebar lacks {$type}");
}

// Detail pages must create missing job rows before drawing task switches;
// otherwise a switch is drawn "off" and the first click turns the task off.
$console = (string)file_get_contents($root . '/app/index/controller/Console.php');
foreach (["Jobs::refreshJob('bilibili', \$mid, \$uid);", "Jobs::refreshJob('netease', \$user_id, (int)\$account['uid']);",
    "Jobs::refreshJob('heybox', \$uid, (int)\$account['uid']);", 'Jobs::refreshJob($type, $userId, $uid);'] as $call) {
    checkinPagesCheck(str_contains($console, $call), 'Detail page no longer refreshes its jobs: ' . $call);
}

echo "Check-in platform page tests passed\n";
