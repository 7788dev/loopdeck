<?php
declare(strict_types=1);

/**
 * Regression tests for the runtime cleanup gates.
 *
 * Covers: the Daka state-file sweep runs on its own gate so disabling task
 * log retention (`TASK_LOG_RETENTION_DAYS=0`) no longer also disables state
 * file cleanup; invalidated accounts (`state` 0) no longer pin their state
 * files forever; a failed prune leaves the stamp untouched so the next
 * scheduler run retries instead of waiting another day.
 */

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\cron\controller\Task;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-daka-prune-' . bin2hex(random_bytes(6)) . '/');
$app->config->set(['interval' => 50, 'reExecute_time' => 300], 'sys');

fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER, timing TEXT, cooling INTEGER DEFAULT 0, data TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, user_id TEXT, do TEXT, response TEXT, addtime TEXT');

Db::name('accounts')->insert(['id' => 1, 'uid' => 1, 'type' => 'netease', 'user_id' => '42',
    'state' => 1, 'timing' => '09:00', 'data' => serialize(['user_id' => '42'])]);
Db::name('accounts')->insert(['id' => 2, 'uid' => 1, 'type' => 'netease', 'user_id' => '77',
    'state' => 0, 'timing' => '', 'data' => serialize(['user_id' => '77'])]);

$runtime = (string)runtime_path();
$dakaDirectory = rtrim($runtime, '/\\') . DIRECTORY_SEPARATOR . 'netease-daka';
if (!is_dir($dakaDirectory) && !@mkdir($dakaDirectory, 0775, true) && !is_dir($dakaDirectory)) {
    throw new RuntimeException('Could not create the fixture daka state directory');
}

$activeFile = $dakaDirectory . DIRECTORY_SEPARATOR . hash('sha256', '42') . '.daily.json';
$invalidFile = $dakaDirectory . DIRECTORY_SEPARATOR . hash('sha256', '77') . '.daily.json';
$oldOrphan = $dakaDirectory . DIRECTORY_SEPARATOR . str_repeat('a', 64) . '.seeded';
$freshOrphan = $dakaDirectory . DIRECTORY_SEPARATOR . str_repeat('b', 64) . '.daily.json';

foreach ([$activeFile, $invalidFile, $oldOrphan, $freshOrphan] as $file) {
    file_put_contents($file, '{}');
}
touch($oldOrphan, time() - 400 * 86400);
touch($invalidFile, time() - 40 * 86400); // older than the 30-day retention window

$runner = new Task();
$pruneDaka = new ReflectionMethod(Task::class, 'pruneDakaStateFilesIfDue');
$pruneDaka->setAccessible(true);
$pruneLogs = new ReflectionMethod(Task::class, 'pruneLogsIfDue');
$pruneLogs->setAccessible(true);

// --- State-file sweep: invalidated accounts and true orphans age out ------

$pruneDaka->invoke($runner);

functionalCheck(is_file($activeFile), 'The sweep removed a live account state file');
functionalCheck(!is_file($invalidFile),
    'A state file of an invalidated (state 0) account was retained forever');
functionalCheck(!is_file($oldOrphan), 'The sweep left an aged-out orphan state file behind');
functionalCheck(is_file($freshOrphan), 'The sweep removed a state file younger than the retention window');
functionalCheck(is_file($runtime . 'daka-state-prune.stamp'),
    'A successful state sweep did not advance its own gate');

// --- Split gates: TASK_LOG_RETENTION_DAYS=0 must not stop the sweep -------

unlink($runtime . 'daka-state-prune.stamp');
Db::name('task_logs')->insert(['type' => 'netease', 'user_id' => '42', 'do' => 'sign',
    'response' => '[成功] fixture', 'addtime' => date('Y-m-d H:i:s', time() - 400 * 86400)]);

putenv('TASK_LOG_RETENTION_DAYS=0');
$pruneLogs->invoke($runner);
functionalCheck((int)Db::name('task_logs')->count() === 1,
    'Retention 0 no longer means keep task logs forever');
functionalCheck(!is_file($runtime . 'task-log-prune.stamp'),
    'The skipped log prune still advanced the log stamp');

$otherOrphan = $dakaDirectory . DIRECTORY_SEPARATOR . str_repeat('c', 64) . '.json';
file_put_contents($otherOrphan, '{}');
touch($otherOrphan, time() - 100 * 86400);
$pruneDaka->invoke($runner);
functionalCheck(!is_file($otherOrphan),
    'Disabling task log retention also disabled the Daka state sweep');
functionalCheck(is_file($runtime . 'daka-state-prune.stamp'),
    'The state sweep run under retention 0 did not advance its gate');
unlink($runtime . 'daka-state-prune.stamp');
putenv('TASK_LOG_RETENTION_DAYS');

// --- A failed log prune must not stamp its gate ---------------------------

Db::execute('DROP TABLE qa_task_logs');
$pruneLogs->invoke($runner);
functionalCheck(!is_file($runtime . 'task-log-prune.stamp'),
    'A failed log prune still advanced its 24h stamp');

// --- A failed-closed state sweep must not stamp either; recovery does ------

Db::execute('DROP TABLE qa_accounts');
$pruneDaka->invoke($runner);
functionalCheck(!is_file($runtime . 'daka-state-prune.stamp'),
    'A fail-closed state sweep (unreadable account list) still advanced its 24h stamp');

fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER DEFAULT 1, type TEXT,
    user_id TEXT, state INTEGER, timing TEXT, cooling INTEGER DEFAULT 0, data TEXT');
$pruneDaka->invoke($runner);
functionalCheck(is_file($runtime . 'daka-state-prune.stamp'),
    'A recovered state sweep did not advance its stamp');

putenv('DAKA_STATE_RETENTION_DAYS');

echo "Daka state prune tests passed: split cleanup gates, invalidated accounts age out and failures retry on the next run\n";
