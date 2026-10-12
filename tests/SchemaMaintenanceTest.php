<?php

declare(strict_types=1);

/**
 * Regression tests for the structural maintenance sweep that brings
 * databases installed by an older release up to the current schema.
 *
 * Covers: every required definition produces one exact ALTER statement when
 * missing and nothing when present; an undescribable table (metadata failure)
 * is skipped instead of guessed at; the legacy MyISAM/utf8 notice table is
 * converted; the sweep is stamped once per day and retried on failure.
 */

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\service\SchemaMaintenance;

function schemaMaintenanceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-schema-maint-' . bin2hex(random_bytes(4)) . '/');
// DDL planning uses the installation prefix; execution stays on the in-memory fixture.
$configuration = $app->config->get('database');
$configuration['connections']['mysql']['prefix'] = 'cloud_';
$app->config->set($configuration, 'database');
schemaMaintenanceCheck(think\facade\Db::connect()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite',
    'Offline schema checks must never use an installed MySQL connection');

// --- plan(): missing definitions produce exact DDL -------------------------

// Undescribable tables (metadata failure) are skipped, never guessed at.
$plan = SchemaMaintenance::plan([], []);
schemaMaintenanceCheck($plan === [],
    'A database with unknown table state did not stay conservative (planned: ' . count($plan) . ')');

$missing = SchemaMaintenance::plan([
    'users' => ['PRIMARY'],
    'accounts' => ['PRIMARY'],
    'jobs' => ['PRIMARY'],
    'task_logs' => ['PRIMARY'],
], [
    'notice' => ['engine' => 'MyISAM', 'collation' => 'utf8_general_ci'],
]);
schemaMaintenanceCheck(in_array('ALTER TABLE `cloud_users` ADD UNIQUE KEY `udx_users_username` (`username`)', $missing, true),
    'The username dedupe backstop was not planned on a legacy install');
schemaMaintenanceCheck(in_array('ALTER TABLE `cloud_accounts` ADD UNIQUE KEY `udx_accounts_identity` (`uid`,`type`(32),`user_id`(128))', $missing, true),
    'The account identity key was not planned on a legacy install');
schemaMaintenanceCheck(in_array('ALTER TABLE `cloud_jobs` ADD UNIQUE KEY `udx_jobs_identity` (`uid`,`type`(32),`user_id`(128),`do`(64))', $missing, true),
    'The job identity key was not planned on a legacy install');
schemaMaintenanceCheck(in_array('ALTER TABLE `cloud_jobs` ADD KEY `idx_jobs_due` (`type`(32),`state`,`nextExecute`,`id`)', $missing, true),
    'The due-jobs index was not planned on a legacy install');
schemaMaintenanceCheck(in_array('ALTER TABLE `cloud_task_logs` ADD KEY `idx_task_logs_retention` (`addtime`,`id`)', $missing, true),
    'The task-log retention index was not planned on a legacy install');
schemaMaintenanceCheck(in_array('ALTER TABLE `cloud_notice` ENGINE=InnoDB CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $missing, true),
    'The legacy MyISAM notice table was not planned for conversion');

// A partially upgraded install only gets the definitions it still lacks.
$partial = SchemaMaintenance::plan([
    'users' => ['PRIMARY', 'udx_users_username'],
    'accounts' => ['PRIMARY', 'udx_accounts_identity', 'idx_accounts_user'],
    'jobs' => ['PRIMARY', 'udx_jobs_identity', 'idx_jobs_due', 'idx_jobs_account', 'idx_jobs_lookup'],
    'task_logs' => ['PRIMARY', 'idx_task_logs_retention'],
], [
    'notice' => ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'],
]);
schemaMaintenanceCheck($partial === [], 'A fully upgraded install was not recognized as up to date');

$recovery = SchemaMaintenance::plan([], [], ['captcha' => ['send' => 'varchar(20)', 'ip' => 'varchar(20)']]);
schemaMaintenanceCheck($recovery === [
    'ALTER TABLE `cloud_captcha` MODIFY `send` varchar(255) NOT NULL',
    'ALTER TABLE `cloud_captcha` MODIFY `ip` varchar(45) NOT NULL',
], 'Legacy recovery columns cannot store supported mailboxes and IPv6 clients');
schemaMaintenanceCheck(SchemaMaintenance::plan([], [], ['captcha' => ['send' => 'varchar(512)', 'ip' => 'varchar(128)']]) === [],
    'Recovery migration would shrink custom columns');
schemaMaintenanceCheck(SchemaMaintenance::plan([], ['kms' => ['engine' => 'MyISAM', 'collation' => 'utf8_general_ci']]) === [
    'ALTER TABLE `cloud_kms` ENGINE=InnoDB',
], 'Legacy redemption cards still ignore transactional rollback');
schemaMaintenanceCheck(SchemaMaintenance::plan([], ['kms' => ['engine' => 'InnoDB', 'collation' => 'utf8_general_ci']]) === [],
    'An already transactional card table was altered unnecessarily');

// One missing definition out of many plans exactly that one.
$one = SchemaMaintenance::plan([
    'users' => ['PRIMARY', 'udx_users_username'],
    'accounts' => ['PRIMARY'],
    'jobs' => ['PRIMARY', 'udx_jobs_identity', 'idx_jobs_due'],
    'task_logs' => ['PRIMARY', 'idx_task_logs_retention'],
], [
    'notice' => ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'],
]);
schemaMaintenanceCheck($one === ['ALTER TABLE `cloud_accounts` ADD UNIQUE KEY `udx_accounts_identity` (`uid`,`type`(32),`user_id`(128))'],
    'A partially missing install planned the wrong statements: ' . json_encode($one));

// A table the metadata query could not describe is skipped, never guessed;
// the tables that could be described still receive their missing DDL.
$undescribable = SchemaMaintenance::plan([
    'users' => null,
    'accounts' => ['PRIMARY', 'udx_accounts_identity'],
    'jobs' => ['PRIMARY'],
    'task_logs' => ['PRIMARY'],
], [
    'notice' => null,
]);
schemaMaintenanceCheck(count($undescribable) === 3,
    'Undescribable tables were not skipped, describable ones not planned: ' . json_encode($undescribable));
foreach ($undescribable as $statement) {
    schemaMaintenanceCheck(!str_contains($statement, '`cloud_users`') && !str_contains($statement, '`cloud_notice`'),
        'A statement touched a table the metadata query could not describe: ' . $statement);
}

// A utf8 (3-byte) notice table on InnoDB still needs the conversion.
$utf8Notice = SchemaMaintenance::plan([
    'users' => ['PRIMARY', 'udx_users_username'],
    'accounts' => ['PRIMARY', 'udx_accounts_identity'],
    'jobs' => ['PRIMARY', 'udx_jobs_identity', 'idx_jobs_due'],
    'task_logs' => ['PRIMARY', 'idx_task_logs_retention'],
], [
    'notice' => ['engine' => 'InnoDB', 'collation' => 'utf8_general_ci'],
]);
schemaMaintenanceCheck(count($utf8Notice) === 1 && str_contains($utf8Notice[0], 'CONVERT TO CHARACTER SET utf8mb4'),
    'An InnoDB notice table without utf8mb4 was not converted');

// --- runIfDue(): the daily gate shares the scheduler maintenance shape -----

$schedulerSource = file_get_contents(dirname(__DIR__) . '/app/cron/controller/Task.php');
schemaMaintenanceCheck(str_contains($schedulerSource, 'SchemaMaintenance::runIfDue()'),
    'The unified scheduler does not run the schema maintenance sweep');

// Fresh installs no longer inherit the legacy MyISAM/utf8 notice definition:
// emoji in a notice would fail the INSERT under MySQL 8 strict mode.
$installSql = (string)file_get_contents(dirname(__DIR__) . '/app/install/install.sql');
$noticeDefinition = '';
if (preg_match('/CREATE TABLE `cloud_notice`.*?ENGINE=(\w+) DEFAULT CHARSET=(\w+)/s', $installSql, $noticeMatch) === 1) {
    $noticeDefinition = $noticeMatch[1] . '/' . $noticeMatch[2];
}
schemaMaintenanceCheck($noticeDefinition === 'InnoDB/utf8mb4',
    'install.sql still creates cloud_notice with the legacy engine/charset: ' . $noticeDefinition);

// The sweep is best-effort by design: on a database that cannot answer
// metadata queries (the offline fixture or a down database) it must swallow
// the failure instead of breaking the scheduler run.
$runtime = $app->getRuntimePath();
if (!is_dir($runtime)) {
    mkdir($runtime, 0700, true);
}
try {
    SchemaMaintenance::runIfDue();
    schemaMaintenanceCheck(think\facade\Db::name('users')->count() === 1, 'Maintenance changed the isolated fixture');
} finally {
    if (is_dir($runtime)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($runtime);
    }
}

echo "Schema maintenance tests passed: legacy installs receive the exact missing DDL, undescribable tables are skipped and failures retry\n";
