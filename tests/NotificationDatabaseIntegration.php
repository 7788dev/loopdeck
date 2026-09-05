<?php

declare(strict_types=1);

// Explicit opt-in: use a disposable database, never the application database.
require dirname(__DIR__) . '/vendor/autoload.php';

use app\cron\controller\Task;
use app\service\EpicSchedule;
use app\service\EpicSubscription;
use app\service\EpicTaskExecutor;
use app\service\NotificationRepository;
use app\service\NotificationService;
use app\service\NotificationSite;
use app\service\NotificationTransport;
use app\service\UserNotificationSettings;
use think\facade\Db;

$database = (string)getenv('LOOPDECK_TEST_DATABASE');
if (!preg_match('/\Aloopdeck_qa_[a-z0-9_]+\z/', $database)) {
    throw new RuntimeException('Set LOOPDECK_TEST_DATABASE to a disposable loopdeck_qa_* database');
}
$app = new think\App(dirname(__DIR__) . '/');
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . PHP_EOL);
    exit(1);
});
require_once dirname(__DIR__) . '/app/common.php';
$connection = $app->config->get('database.connections.mysql');
$connection = array_replace($connection, [
    'hostname' => getenv('LOOPDECK_TEST_HOST') ?: '127.0.0.1', 'database' => $database,
    'username' => getenv('LOOPDECK_TEST_USER'), 'password' => getenv('LOOPDECK_TEST_PASSWORD'),
    'prefix' => 'cloud_', 'charset' => 'utf8mb4',
]);
$app->config->set(['default' => 'mysql', 'connections' => ['mysql' => $connection]], 'database');

function integrationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

integrationCheck(Db::query('SELECT DATABASE() AS db')[0]['db'] === $database, 'Wrong database connection');
integrationCheck(Db::query('SHOW TABLES') === [], 'The disposable database must be empty');
$pdo = Db::connect()->getPdo();
$pdo->exec(file_get_contents(dirname(__DIR__) . '/app/install/install.sql'));
Db::name('weblist')->insert(['web_id' => 1, 'webname' => 'LoopDeck QA', 'title' => '测试面板',
    'domain' => '127.0.0.1:18082', 'prefix' => 'cloud_', 'web_key' => 'qa-inert-key']);
foreach ([1, 2] as $uid) {
    Db::name('users')->insert(['uid' => $uid, 'web_id' => 1, 'username' => 'qa_user_' . $uid,
        'nickname' => $uid === 1 ? '测试用户 🎵' : '隔离用户', 'password' => password_hash('qa-only-inert-password', PASSWORD_DEFAULT),
        'mail' => 'qa' . $uid . '@example.com', 'state' => 1, 'power' => $uid === 1 ? 100 : 0,
        'sid' => 'qa-session-' . $uid, 'vip_start' => date('Y-m-d'), 'vip_end' => '2099-12-31']);
}
Db::name('user_notifications')->insert(['uid' => 1, 'web_id' => 1, 'bark_token' => 'qa_legacy_device', 'updated_at' => date('Y-m-d H:i:s')]);

$repository = new NotificationRepository();
$repository->ensureSchema();
(new NotificationRepository())->ensureSchema();
$preferences = new UserNotificationSettings($repository);
integrationCheck($preferences->get(1, 1)['bark_token'] === 'qa_legacy_device', 'Legacy Bark migration failed');
integrationCheck($preferences->get(2, 1)['bark_token'] === '', 'A second user received the first user token');
$input = ['enabled' => 1, 'bark_enabled' => 1, 'pushplus_enabled' => 1,
    'pushplus_token' => 'qa_pushplus_token', 'daily_summary' => 1, 'task_success' => 1, 'task_failure' => 1,
    'epic_free_games' => 1, 'summary_time' => '22:00', 'epic_time' => '09:00'];
Db::transaction(static function () use ($preferences, $input): void {
    $preferences->save(1, 1, $input, false);
    EpicSubscription::sync(1, 1, $preferences->get(1, 1));
});
EpicSubscription::sync(1, 1, $preferences->get(1, 1));
integrationCheck(Db::name('jobs')->where('type', 'epic')->count() === 1, 'Epic subscription was duplicated');
$repository->migratePreferences(1, 1, UserNotificationSettings::defaults());
integrationCheck(json_decode($repository->preferences(1, 1)['settings'], true)['enabled'], 'A stale migration overwrote saved preferences');
integrationCheck(!array_key_exists('bark_token', $preferences->publicSettings(1, 1)), 'A saved token was exposed');
integrationCheck(!(new NotificationSite())->get(1)['email_available'], 'Unconfigured email was made available');
try {
    $preferences->save(1, 1, $input + ['email_enabled' => 1, 'email_address' => 'qa@example.com'], false);
    throw new LogicException('Unconfigured email was enabled through the backend');
} catch (InvalidArgumentException $expected) {
}

$transport = new class extends NotificationTransport {
    public array $sent = [];
    public bool $failBark = true;
    public function send(string $channel, array $settings, array $site, string $title, string $body, string $url = ''): array
    {
        $this->sent[] = compact('channel', 'title', 'body');
        return ['success' => $channel !== 'bark' || !$this->failBark, 'message' => 'QA transport'];
    }
};
$service = new NotificationService($repository, $preferences, new NotificationSite(), $transport);
$user = Db::name('users')->where('uid', 1)->find();
$service->recordTask($user, 'netease', 'qa_account', 'sign', '每日签到 🎵', ['success' => true, 'message' => '签到完成']);
$service->recordTask($user, 'netease', 'qa_account', 'sign', '每日签到 🎵', ['success' => true, 'message' => '签到完成']);
$service->recordTask($user, 'netease', 'qa_account', 'daka_new', '每日听歌', ['success' => false, 'message' => '等待入账', 'retry_after_seconds' => 300]);
integrationCheck(Db::name('notification_outbox')->count() === 2, 'Duplicate task pushes or retry-as-failure pushes were queued');
integrationCheck(count($repository->dailyTasks(1, 1, date('Y-m-d'))) === 2, 'Daily task upsert failed');
integrationCheck($repository->dailyTasks(2, 1, date('Y-m-d')) === [], 'Daily summary leaked to a second user');
$now = time();
Db::name('user_notification_preferences')->where('uid', 1)->update(['next_summary_at' => $now - 1]);
$preferences->save(1, 1, $input, false);
integrationCheck((int)$repository->preferences(1, 1)['next_summary_at'] === $now - 1, 'A pending daily report was skipped when saving');
$tick = $service->tick(20, 20, $now);
integrationCheck($tick['summaries'] === 1 && $tick['sent'] === 2 && $tick['retrying'] === 2, 'Database outbox did not independently process channels and summary');
$transport->failBark = false;
$tick = $service->tick(20, 20, $now + 301);
integrationCheck($tick['sent'] === 2 && Db::name('notification_outbox')->where('status', 1)->count() === 4, 'Database delivery retries failed');
$row = $repository->dailyTasks(1, 1, date('Y-m-d'));
integrationCheck(str_contains(json_encode($row, JSON_UNESCAPED_UNICODE), '🎵'), 'Four-byte characters were lost');

$epic = new EpicTaskExecutor($service, static fn(): array => [['title' => 'QA 免费游戏', 'available' => true,
    'start_at' => $now - 3600, 'end_at' => $now + 86400, 'productUrl' => 'https://store.epicgames.com/zh-CN/p/qa-game']]);
integrationCheck($epic->execute($user)['success'], 'Epic did not use the selected notification channels');
$epic->execute($user);
integrationCheck(Db::name('notification_outbox')->where('event_type', 'epic_free_games')->count() === 2, 'Epic weekly reminder was duplicated');
// Exercise the real scheduler and job runner with an inert catalog adapter.
final class QaEpicCatalog
{
    public static array $games;
    public function getWeeklyFreeGames(): array { return self::$games; }
}
QaEpicCatalog::$games = [['title' => 'QA 免费游戏', 'available' => true, 'start_at' => $now - 3600,
    'end_at' => $now + 86400, 'productUrl' => 'https://store.epicgames.com/zh-CN/p/qa-game']];
class_alias(QaEpicCatalog::class, 'epic\\Epic');
Db::name('jobs')->where('type', 'epic')->where('uid', 1)->update(['nextExecute' => $now - 30]);
EpicSubscription::sync(1, 1, $preferences->get(1, 1));
$epicJob = Db::name('jobs')->where('type', 'epic')->where('uid', 1)->find();
integrationCheck((int)$epicJob['nextExecute'] === $now - 30, 'Editing preferences skipped an Epic reminder awaiting execution');
$epicScheduler = new Task();
$runJob = new ReflectionMethod($epicScheduler, 'runJob');
$runJob->setAccessible(true);
$summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'disabled' => 0];
$runJob->invokeArgs($epicScheduler, [$epicJob, &$summary]);
integrationCheck($summary['succeeded'] === 1, 'The unified scheduler did not execute the Epic reminder');
integrationCheck((int)Db::name('jobs')->where('id', $epicJob['id'])->value('nextExecute') === EpicSchedule::next('09:00'), 'Epic did not advance after queueing');
foreach (['2026-09-04 08:00:00' => '2026-09-04 09:00:00', '2026-09-05 08:00:00' => '2026-09-11 09:00:00'] as $from => $to) {
    integrationCheck(EpicSchedule::next('09:00', strtotime($from)) === strtotime($to), 'Epic skipped an extra week');
}
$claimNow = time();
$due = $repository->dueMessages($claimNow, 20)[0];
integrationCheck($repository->claimMessage((int)$due['id'], (int)$due['available_at'], $claimNow), 'Message lease was not acquired');
integrationCheck(!$repository->claimMessage((int)$due['id'], (int)$due['available_at'], $claimNow), 'Two workers acquired the same message');

Db::name('users')->where('uid', 2)->update(['state' => 0]);
$scheduler = new Task();
$loadUser = new ReflectionMethod($scheduler, 'user');
$loadUser->setAccessible(true);
integrationCheck($loadUser->invoke($scheduler, 2) === null, 'A blocked user could enter the scheduler');
Db::name('users')->where('uid', 2)->update(['state' => 1]);
$repository->prune($now);

Db::name('jobs')->insert(['uid' => 2, 'zid' => 1, 'type' => 'epic', 'do' => 'weeklyGameNotify',
    'user_id' => 'old-epic@example.com', 'data' => serialize(['timing' => '10:30']), 'state' => 1]);
$legacyEmail = (new UserNotificationSettings($repository))->get(2, 1);
integrationCheck($legacyEmail['channels']['email'] && $legacyEmail['events']['epic_free_games']
    && $legacyEmail['email_address'] === 'old-epic@example.com', 'Legacy Epic email subscription did not migrate');

// Leave inert, disabled preferences for optional UI verification in this database.
foreach ([1, 2] as $uid) {
    $preferences->save($uid, 1, ['enabled' => 0, 'summary_time' => '22:00', 'epic_time' => '09:00'], false);
}
Db::name('notification_outbox')->where('status', 0)->update(['status' => 3]);
echo "Notification database, scheduler isolation and Epic integration checks passed\n";
