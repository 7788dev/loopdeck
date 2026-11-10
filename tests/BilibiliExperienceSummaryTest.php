<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\controller\Console;
use app\service\BilibiliExperienceSummary;
use think\facade\Db;

function experienceCheck(bool $condition, string $message): void
{
    functionalCheck($condition, $message);
}

$reward = ['code' => 0, 'data' => ['login' => true, 'watch' => true, 'share' => true, 'coins' => 5]];
$coin = ['code' => 0, 'data' => 30];
$vip = ['code' => 0, 'data' => ['is_vip' => true, 'vip_status' => 1, 'list' => [['type' => 9, 'state' => 1]]]];
$config = ['add_coin_mode' => 'random', 'add_coin_num' => 3, '_bilibili_verification' => ['attempt' => 1]];
$originalConfig = $config;
$summary = BilibiliExperienceSummary::build($reward, $coin, $vip, $config, 1);
experienceCheck($summary['basic_confirmed'] === 40 && $summary['vip_confirmed'] === 10
    && $summary['confirmed_total'] === 50, 'Daily level experience mixed base, VIP or retired share rewards');
experienceCheck(array_column($summary['components'], 'limit') === [5, 5, 50, 10], 'Daily component limits changed');
experienceCheck(!$summary['has_unconfirmed'] && $summary['coin_target_exp'] === 30
    && str_contains($summary['coin_plan'], '配置 3 枚 / 日'), 'Configured coin target was not explained');
experienceCheck($config === $originalConfig, 'Read-only summary changed job configuration');

$pending = BilibiliExperienceSummary::build(
    ['code' => 0, 'data' => ['login' => true, 'watch' => false]],
    ['code' => 0, 'data' => 0],
    ['code' => 0, 'data' => ['is_vip' => true, 'list' => [['type' => 9, 'state' => 2]]]],
    $config,
    1
);
experienceCheck($pending['basic_confirmed'] === 5 && $pending['vip_confirmed'] === null
    && $pending['confirmed_total'] === 5 && $pending['has_unconfirmed'], 'Unsettled rewards were counted as confirmed');
experienceCheck($pending['components'][1]['state'] === 'pending' && $pending['components'][1]['confirmed_exp'] === null
    && $pending['components'][2]['confirmed_exp'] === 0 && $pending['components'][3]['state'] === 'pending',
    'A known zero coin balance and an unconfirmed reward became indistinguishable');
$allPending = BilibiliExperienceSummary::build(['code' => 0, 'data' => ['login' => false, 'watch' => false]], [], []);
experienceCheck($allPending['basic_confirmed'] === null && $allPending['confirmed_total'] === null
    && $allPending['basic_placeholder'] === '待确认', 'False reward flags manufactured a confirmed zero');

$missing = BilibiliExperienceSummary::build(['code' => 0, 'data' => []], ['code' => 0], ['code' => 0, 'data' => []]);
experienceCheck($missing['basic_confirmed'] === null && $missing['vip_confirmed'] === null
    && $missing['confirmed_total'] === null && !$missing['vip_skipped'], 'Missing fields became zero rewards or non-VIP');
experienceCheck(array_unique(array_column($missing['components'], 'state')) === ['unknown']
    && $missing['total_placeholder'] === '暂不可用', 'Missing fields were presented as pending or completed');
$failed = BilibiliExperienceSummary::build(
    ['code' => -500, 'data' => ['login' => true, 'watch' => true]],
    ['code' => -500, 'data' => 50],
    ['code' => -500, 'data' => $vip['data']]
);
experienceCheck($failed['confirmed_total'] === null && !$failed['vip_skipped'], 'Failed reads trusted stale response data');
foreach ([null, true, false, [], 'unknown', -1, 3.5, 51, INF, NAN] as $badCoin) {
    $result = BilibiliExperienceSummary::build([], ['code' => 0, 'data' => $badCoin], []);
    experienceCheck($result['components'][2]['confirmed_exp'] === null, 'Malformed coin experience was counted');
}
$maxCoins = BilibiliExperienceSummary::build($reward, ['code' => 0, 'data' => '50'], []);
experienceCheck($maxCoins['basic_confirmed'] === 60, 'Valid numeric coin experience was lost');
foreach (['true', 1, 0, null] as $badFlag) {
    $result = BilibiliExperienceSummary::build(['code' => 0, 'data' => ['login' => $badFlag, 'watch' => $badFlag]], [], []);
    experienceCheck($result['basic_confirmed'] === null && $result['components'][0]['state'] === 'unknown',
        'Nonboolean reward flags were accepted');
}

$nonVip = BilibiliExperienceSummary::build($reward, $coin,
    ['code' => 0, 'data' => ['is_vip' => false, 'vip_status' => 0, 'list' => []]], $config, 0);
experienceCheck($nonVip['vip_skipped'] && $nonVip['vip_confirmed'] === null && $nonVip['confirmed_total'] === 40,
    'Non-VIP skip was counted as a claimed reward');
experienceCheck($nonVip['coin_target_exp'] === 30 && str_contains($nonVip['coin_plan'], '已关闭')
    && str_contains($nonVip['coin_plan'], '不会自动投币') && !str_contains($nonVip['coin_plan'], '对应目标'),
    'Disabled coin task implied future rewards or hid the retained configuration');
foreach ([[], ['type' => 9], ['type' => 9, 'state' => null], ['type' => 9, 'state' => false],
    ['type' => 9, 'state' => 'invalid'], ['type' => 3, 'state' => 1]] as $benefit) {
    $result = BilibiliExperienceSummary::build([], [], ['code' => 0, 'data' => ['is_vip' => true, 'list' => [$benefit]]]);
    experienceCheck($result['vip_confirmed'] === null && $result['components'][3]['state'] === 'unknown',
        'A missing or unrelated VIP state counted as claimed or claimable');
}
$claimable = BilibiliExperienceSummary::build([], [],
    ['code' => 0, 'data' => ['vip_status' => 1, 'list' => [['type' => 9, 'state' => 0]]]]);
experienceCheck($claimable['vip_confirmed'] === null && $claimable['components'][3]['state'] === 'pending',
    'Claimable VIP experience was already counted');
experienceCheck(BilibiliExperienceSummary::build([], [], $vip)['confirmed_total'] === 10,
    'A failed basic read hid confirmed VIP experience');
foreach ([0, 6, 'invalid', false, null] as $badTarget) {
    $result = BilibiliExperienceSummary::build([], [], [], ['add_coin_num' => $badTarget], 1);
    experienceCheck($result['coin_target_exp'] === null && str_contains($result['coin_plan'], '尚未配置'),
        'Invalid configuration created a coin target');
}
experienceCheck(str_contains(BilibiliExperienceSummary::build([], [], [], $config, -1)['coin_plan'], '已暂停'),
    'Paused coin task was described as enabled');

$navData = ['level_info' => ['current_level' => 5, 'current_exp' => 12000, 'next_exp' => 28800], 'money' => 3.5];
$levelInfo = BilibiliExperienceSummary::levelInfo($navData);
experienceCheck($levelInfo['remaining_exp'] === 16800 && $levelInfo['money'] === '3.5', 'Known level numbers changed');
foreach (['--', null, false, [], -1, 5000, INF, NAN] as $nextExp) {
    $result = BilibiliExperienceSummary::levelInfo(array_replace($navData, [
        'level_info' => array_replace($navData['level_info'], ['next_exp' => $nextExp]),
    ]));
    experienceCheck($result['remaining_exp'] === null, 'Unknown next_exp became zero remaining experience');
}
$maxLevel = BilibiliExperienceSummary::levelInfo(['level_info' => ['current_level' => 6, 'current_exp' => 30000, 'next_exp' => '--']]);
experienceCheck($maxLevel['remaining_exp'] === null && $maxLevel['next_level'] === null, 'LV6 showed a fake upgrade target');
$unknownLevel = BilibiliExperienceSummary::levelInfo(['level_info' => ['current_level' => 5]]);
experienceCheck($unknownLevel['current_exp'] === null && $unknownLevel['remaining_exp'] === null
    && $unknownLevel['money'] === null, 'Missing level or wallet fields became zeros');

// Exercise the actual controller using an in-memory database and a read-only
// SDK double. Any task method or unexpected call throws instead of going online.
final class ExperiencePageSdk
{
    public array $calls = [];
    public array $responses = [];

    public function nav(): array { return $this->read('nav'); }
    public function dailyReward(): array { return $this->read('dailyReward'); }
    public function todayCoinExp(): array { return $this->read('todayCoinExp'); }
    public function vipPrivilege(): array { return $this->read('vipPrivilege'); }
    public function isAuthenticationFailure(array $response): bool { return false; }

    private function read(string $method): array
    {
        $this->calls[] = $method;
        $response = $this->responses[$method] ?? [];
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }
}

final class ExperiencePageClient
{
    public static ExperiencePageSdk $fixture;
    public function __construct(...$args) {}
    public function sdk(): ExperiencePageSdk { return self::$fixture; }
}
class_alias(ExperiencePageClient::class, 'bilibili\Bilibili');

fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, type TEXT, user_id TEXT, state INTEGER, timing TEXT, data TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, type TEXT, user_id TEXT, do TEXT, state INTEGER,
    nextExecute INTEGER, lastExecute TEXT, data TEXT');
$storedProfile = ['mid' => '42', 'mid_md5' => 'fixture-md5', 'token' => 'fixture-token', 'csrf' => 'fixture-csrf',
    'nickname' => '测试账号', 'avatar' => ''];
Db::name('accounts')->insert(['id' => 1, 'uid' => 1, 'type' => 'bilibili', 'user_id' => '42', 'state' => 1,
    'timing' => '09:00', 'data' => serialize($storedProfile)]);
Db::name('tasks')->insert(['id' => 1, 'type' => 'bilibili', 'execute_name' => 'coinadd', 'name' => '每日投币',
    'describe' => '投币经验', 'icon' => 'si si-badge', 'state' => 1, 'more' => 1, 'vip' => 0, 'order' => 4]);
Db::name('jobs')->insert(['id' => 1, 'uid' => 1, 'type' => 'bilibili', 'user_id' => '42', 'do' => 'coinadd',
    'state' => 0, 'nextExecute' => 0, 'data' => serialize($config)]);
foreach (app\service\BilibiliTaskExecutor::OFFLINE_TASKS as $retiredTask => $reason) {
    Db::name('tasks')->insert(['type' => 'bilibili', 'execute_name' => $retiredTask, 'name' => '旧功能-' . $retiredTask,
        'describe' => $reason, 'icon' => 'fa-ban', 'state' => 1, 'more' => 1, 'vip' => 0, 'order' => 0]);
    Db::name('jobs')->insert(['uid' => 1, 'type' => 'bilibili', 'user_id' => '42', 'do' => $retiredTask,
        'state' => 1, 'nextExecute' => time(), 'data' => 'retained historical configuration']);
}
$sdk = new ExperiencePageSdk();
ExperiencePageClient::$fixture = $sdk;
$sdk->responses = [
    'nav' => ['code' => 0, 'data' => ['isLogin' => true] + $navData],
    'dailyReward' => $reward, 'todayCoinExp' => $coin, 'vipPrivilege' => $vip,
];
$page = (new Console())->bilibili('info', '42');
$pageVars = $page->getVars();
experienceCheck(count($pageVars['task_rows']) === 4
    && array_diff(array_column($pageVars['task_rows'], 'execute_name'), ['coinadd', 'dailyexperience', 'vipexperience', 'vippoints']) === [],
    'The actual Bilibili controller still exposes retired tasks');
experienceCheck(Db::name('jobs')->whereIn('do', array_keys(app\service\BilibiliTaskExecutor::OFFLINE_TASKS))
    ->where('state', 0)->where('nextExecute', 0)->where('data', 'retained historical configuration')->count() === 7,
    'Hiding retired tasks erased their history or re-enabled their jobs');
experienceCheck($sdk->calls === ['nav', 'dailyReward', 'todayCoinExp', 'vipPrivilege'],
    'Account page duplicated an upstream read or executed a task');
experienceCheck($pageVars['experience_summary']['confirmed_total'] === 50
    && str_contains($pageVars['experience_summary']['coin_plan'], '已关闭'), 'Controller lost confirmed rewards or job state');
experienceCheck(Db::name('jobs')->where('id', 1)->value('data') === serialize($config)
    && (int)Db::name('jobs')->where('id', 1)->value('state') === 0, 'Rendering changed the coin task');
$sdk->calls = [];
$sdk->responses['dailyReward'] = new RuntimeException('isolated upstream failure');
$sdk->responses['nav']['data']['level_info']['next_exp'] = '--';
$degradedVars = (new Console())->bilibili('info', '42')->getVars();
experienceCheck($sdk->calls === ['nav', 'dailyReward', 'todayCoinExp', 'vipPrivilege']
    && $degradedVars['experience_summary']['confirmed_total'] === 40
    && $degradedVars['level_info']['remaining_exp'] === null, 'A failed read prevented independent reads or invented an upgrade value');

// Render the real page, including escaping and branches for missing values.
define('PJAX', false);
$root = dirname(__DIR__);
$cachePath = sys_get_temp_dir() . '/loopdeck-experience-render-' . bin2hex(random_bytes(6)) . '/';
mkdir($cachePath);
$viewConfig = require $root . '/config/view.php';
unset($viewConfig['type']);
$app->config->set(['webname' => 'LoopDeck', 'title' => '经验测试', 'web_id' => 1, 'user_qq' => '10000'], 'web');
think\facade\Session::set('user.nickname', '测试用户');
think\facade\Session::set('user.qq', '10000');
$baseVars = [
    'data' => ['user_id' => '42', 'state' => 1],
    'a_data' => ['mid' => '42', 'nickname' => '测试账号', 'avatar' => ''],
    'timing' => '09:00', 'level_info' => $levelInfo, 'info_warning' => '', 'task_rows' => [],
    'experience_summary' => $summary,
];
$rendered = [];
$render = static function (array $vars) use ($root, $cachePath, $viewConfig, $baseVars): string {
    $engine = new think\Template(array_replace($viewConfig, [
        'view_path' => $root . '/app/index/view/', 'cache_path' => $cachePath,
    ]));
    ob_start();
    try {
        $engine->fetch('console/bilibili/info', array_replace($baseVars, $vars));
        return (string)ob_get_contents();
    } finally {
        ob_end_clean();
    }
};
$panelText = static function (string $html): string {
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }
    $panel = (new DOMXPath($document))->query('//*[@id="bilibili-experience-summary"]')->item(0);
    experienceCheck($panel !== null, 'Experience summary panel was not rendered');
    return preg_replace('/\s+/u', ' ', $panel->textContent);
};
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});
try {
    $rendered['confirmed'] = $render($pageVars);
    $text = $panelText($rendered['confirmed']);
    experienceCheck(str_contains($text, '基础已确认 +40') && str_contains($text, '大会员已确认 +10')
        && str_contains($text, '合计已确认 +50'), 'Rendered totals lost their confirmation scope');
    experienceCheck(str_contains($text, '大积分可用于兑换权益，不计入等级经验。')
        && !str_contains($text, '分享'), 'Rendered panel confused points or share with level experience');
    $rendered['pending'] = $render(['experience_summary' => $pending]);
    $text = $panelText($rendered['pending']);
    experienceCheck(str_contains($text, '每日观看每日上限 5 — 待确认') && str_contains($text, '每日投币每日上限 50 0 已确认')
        && str_contains($text, '大会员加成每日上限 10 — 待确认') && str_contains($text, '仅统计已确认项目'),
        'Pending rewards, known zero or partial totals rendered incorrectly');
    $rendered['missing'] = $render(['experience_summary' => $missing, 'level_info' => $unknownLevel]);
    $text = $panelText($rendered['missing']);
    experienceCheck(str_contains($text, '合计已确认 暂不可用') && !str_contains($text, '+0')
        && !str_contains($text, '非大会员'), 'Missing state rendered as zero or non-VIP');
    experienceCheck(str_contains($rendered['missing'], '升级所需经验暂不可用')
        && !str_contains($rendered['missing'], '还需 0 经验'), 'Unknown remaining experience rendered as zero');
    $rendered['non-vip'] = $render(['experience_summary' => $nonVip]);
    $text = $panelText($rendered['non-vip']);
    experienceCheck(str_contains($text, '大会员已确认 不适用') && str_contains($text, '不会自动投币'),
        'Non-VIP or disabled task explanations were lost');
    $rendered['degraded'] = $render($degradedVars);
    $text = $panelText($rendered['degraded']);
    experienceCheck(str_contains($text, '每日登录每日上限 5 — 暂不可用') && str_contains($text, '合计已确认 +40'),
        'An upstream exception concealed confirmed components in the page');
    $rendered['max-level'] = $render(['level_info' => $maxLevel]);
    experienceCheck(str_contains($rendered['max-level'], '等级已达满级')
        && !str_contains($rendered['max-level'], '距升级') && !str_contains($rendered['max-level'], '还需 0 经验'),
        'LV6 showed a misleading next-level target');
    $payload = '<img src=x onerror=alert(1)>';
    $unsafeSummary = $summary;
    $unsafeSummary['components'][0]['detail'] = $payload;
    $unsafeSummary['coin_plan'] = $payload;
    $rendered['escaping'] = $render(['experience_summary' => $unsafeSummary, 'info_warning' => $payload]);
    experienceCheck(!str_contains($rendered['escaping'], $payload)
        && substr_count($rendered['escaping'], html_escape($payload)) >= 3, 'Experience copy bypassed HTML escaping');
} finally {
    restore_error_handler();
    foreach (glob($cachePath . '*') as $file) {
        unlink($file);
    }
    rmdir($cachePath);
}
// Optional fixture-only previews stay outside the repository.
$previewPath = getenv('LOOPDECK_EXPERIENCE_PREVIEW_DIR');
if ($previewPath && is_dir($previewPath)) {
    foreach ($rendered as $name => $html) {
        file_put_contents($previewPath . '/' . $name . '.html', $html);
    }
}
echo "Bilibili experience summary, read-only controller and rendered page passed\n";
