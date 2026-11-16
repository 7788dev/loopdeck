<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\index\controller\Console;
use app\index\model\Tasks;
use app\index\model\Weblist;
use app\service\SiteStatistics;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

function statisticsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporary = sys_get_temp_dir() . '/loopdeck-site-statistics-' . bin2hex(random_bytes(6));
$app->setRuntimePath($temporary . '/runtime/');
$app->instance('cache', new think\cache\driver\File($app, ['path' => $temporary . '/cache/']));

$sqlLog = [];
Db::listen(static function (string $sql) use (&$sqlLog): void {
    if (!str_starts_with($sql, 'CONNECT:')) {
        $sqlLog[] = $sql;
    }
});
$queriesSince = static function (int &$offset) use (&$sqlLog): array {
    $slice = array_slice($sqlLog, $offset);
    $offset = count($sqlLog);
    return $slice;
};
$offset = 0;

fixtureTable('accounts', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER DEFAULT 1, timing TEXT, cooling INTEGER DEFAULT 0, addtime TEXT, data TEXT');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT, user_id TEXT,
    do TEXT, state INTEGER, nextExecute INTEGER, lastExecute TEXT, data TEXT');
fixtureTable('info', 'sysid INTEGER PRIMARY KEY, times INTEGER DEFAULT 0, last TEXT');
fixtureTable('notice', 'id INTEGER PRIMARY KEY AUTOINCREMENT, zid INTEGER DEFAULT 1, type INTEGER, sort INTEGER DEFAULT 0,
    title TEXT, content TEXT, addtime TEXT');
fixtureTable('tasks', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, execute_name TEXT, name TEXT, describe TEXT,
    icon TEXT, execute_rate INTEGER, execute_url TEXT, more INTEGER, state INTEGER, vip INTEGER, time TEXT, "order" INTEGER');

Db::name('users')->insert(['uid' => 2, 'web_id' => 1, 'username' => 'second', 'sid' => 's2', 'quota' => 5]);
Db::name('users')->insert(['uid' => 3, 'web_id' => 2, 'username' => 'foreign', 'sid' => 's3', 'quota' => 5]);
Db::name('accounts')->insert(['uid' => 1, 'zid' => 1, 'type' => 'netease', 'user_id' => '1001', 'data' => 'a:0:{}']);
Db::name('accounts')->insert(['uid' => 2, 'zid' => 1, 'type' => 'netease', 'user_id' => '1002', 'data' => 'a:0:{}']);
Db::name('accounts')->insert(['uid' => 1, 'zid' => 2, 'type' => 'netease', 'user_id' => '1003', 'data' => 'a:0:{}']);
Db::name('jobs')->insert(['uid' => 1, 'type' => 'netease', 'user_id' => '1001', 'do' => 'sign', 'state' => 1, 'nextExecute' => 0]);
Db::name('jobs')->insert(['uid' => 2, 'type' => 'netease', 'user_id' => '1002', 'do' => 'sign', 'state' => 1, 'nextExecute' => 0]);
Db::name('info')->insert(['sysid' => 100, 'times' => 42]);
$queriesSince($offset);

$counters = SiteStatistics::counters();
statisticsCheck($counters === ['user_count' => 2, 'account_count' => 2, 'job_count' => 2, 'execute_count' => 42],
    'Display counters did not match the installed data');
$firstPass = $queriesSince($offset);
statisticsCheck(count($firstPass) === 4, 'The cold snapshot should read each aggregate exactly once');

Db::name('users')->insert(['uid' => 4, 'web_id' => 1, 'username' => 'third', 'sid' => 's4', 'quota' => 5]);
Db::name('jobs')->insert(['uid' => 1, 'type' => 'netease', 'user_id' => '1001', 'do' => 'daka_new', 'state' => 1, 'nextExecute' => 0]);
$queriesSince($offset);
statisticsCheck(SiteStatistics::counters() === $counters, 'Cached counters changed inside the TTL');
statisticsCheck($queriesSince($offset) === [], 'A warm counter read still queried the database');

Cache::delete('site_display_statistics');
$refreshed = SiteStatistics::counters();
statisticsCheck($refreshed['user_count'] === 3 && $refreshed['job_count'] === 3,
    'Refreshing the snapshot did not pick up new rows');
$queriesSince($offset);

// The user dashboard reads the shared snapshot, while the personal quota stays live.
Session::set('user', Db::name('users')->find(1));
$view = (new Console())->index();
$vars = $view->getVars();
statisticsCheck($vars['user_count'] === 3 && $vars['account_count'] === 2
    && $vars['job_count'] === 3 && $vars['execute_count'] === 42,
    'Dashboard did not render the cached site counters');
statisticsCheck($vars['quota_used'] === 2, 'Dashboard lost the live personal account count');
$dashboardQueries = $queriesSince($offset);
statisticsCheck(count(array_filter($dashboardQueries, static fn(string $sql): bool => str_contains($sql, 'COUNT('))) === 1,
    'Dashboard repeated the site-wide aggregate queries instead of reusing the snapshot');

// The home page site age comes from the site row LoadConfigs already caches.
$app->config->set(['start_time' => date('Y-m-d', time() - 10 * 86400)], 'web');
$queriesSince($offset);
statisticsCheck(Weblist::start_Time() === 10, 'Site age was not computed from the cached start date');
statisticsCheck($queriesSince($offset) === [], 'Site age re-queried the cached site row');

// Builtin catalog sync batches its existence check into one SELECT per platform.
$synced = new ReflectionProperty(Tasks::class, 'builtinTasksSynced');
$synced->setAccessible(true);
$synced->setValue(null, []);
Db::name('tasks')->insert(['type' => 'bilibili', 'execute_name' => 'dailyexperience', 'name' => '每日经验任务',
    'describe' => '登录、观看、分享、投币经验状态与经验日志核验', 'state' => 0, 'vip' => 0, 'order' => 2]);
Db::name('tasks')->insert(['type' => 'bilibili', 'execute_name' => 'vipexperience', 'name' => '大会员每日经验',
    'describe' => '管理员自定义说明', 'state' => 1, 'vip' => 0, 'order' => 3]);
$queriesSince($offset);
$catalog = Tasks::getTaskList('bilibili')->toArray();
$syncQueries = $queriesSince($offset);
$taskSelects = array_filter($syncQueries, static fn(string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'qa_tasks'));
$taskInserts = array_filter($syncQueries, static fn(string $sql): bool => str_starts_with($sql, 'INSERT') && str_contains($sql, 'qa_tasks'));
statisticsCheck(count($taskSelects) === 2, 'Builtin sync did not batch its existence check into one SELECT');
statisticsCheck(count($taskInserts) === 1, 'Builtin sync inserted rows that already existed');
statisticsCheck(count($catalog) === 2, 'Administrator-disabled task leaked into the visible catalog');
$daily = Db::name('tasks')->where('execute_name', 'dailyexperience')->find();
statisticsCheck($daily['describe'] === '核验登录、观看与投币基础经验，等待到账时自动复查' && (int)$daily['state'] === 0,
    'Legacy description sync failed or re-enabled an administrator-disabled task');
statisticsCheck(Db::name('tasks')->where('execute_name', 'vipexperience')->value('describe') === '管理员自定义说明',
    'Builtin sync overwrote an administrator customisation');

// A fresh installation still receives every builtin row through the batched path.
$synced->setValue(null, []);
Db::name('tasks')->where('type', 'bilibili')->delete();
$queriesSince($offset);
$fresh = Tasks::getTaskList('bilibili')->toArray();
$freshQueries = $queriesSince($offset);
statisticsCheck(count($fresh) === 3, 'Fresh installation did not receive all builtin tasks');
statisticsCheck(count(array_filter($freshQueries, static fn(string $sql): bool => str_starts_with($sql, 'INSERT') && str_contains($sql, 'qa_tasks'))) === 3,
    'Fresh installation did not insert each missing builtin task');
statisticsCheck(count(array_filter($freshQueries, static fn(string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'qa_tasks'))) === 2,
    'Fresh installation sync was not batched');

echo "Site statistics cache and builtin catalog batching tests passed\n";