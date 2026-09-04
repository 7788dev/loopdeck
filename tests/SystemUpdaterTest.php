<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\service\ApplicationVersion;
use app\service\SystemUpdater;

function updaterCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

updaterCheck(ApplicationVersion::current() === '1.1.27', 'Local VERSION was not loaded');
updaterCheck(app_version() === '1.1.27', 'Template asset version was not loaded');
updaterCheck(ApplicationVersion::normalize('v1.2.3') === '1.2.3', 'Version normalization failed');
updaterCheck(ApplicationVersion::normalize('latest') === null, 'Invalid version was accepted');

$stateFile = tempnam(sys_get_temp_dir(), 'loopdeck-updater-');
if ($stateFile === false) {
    throw new RuntimeException('Unable to create updater state fixture');
}
file_put_contents($stateFile, json_encode([
    'schema' => 1,
    'enabled' => true,
    'status' => 'updated',
    'checked_at' => '2026-09-04T01:00:00Z',
    'last_update_at' => '2026-09-04T01:00:00Z',
    'next_check_at' => '2026-09-04T07:00:00Z',
    'latest_version' => '1.1.27',
    'version_source' => 'https://raw.githubusercontent.com/7788dev/loopdeck/main/VERSION',
    'version_sources' => [
        ['source' => 'https://raw.githubusercontent.com/7788dev/loopdeck/main/VERSION', 'version' => '1.1.27'],
    ],
    'image_repository' => 'ghcr.nju.edu.cn/7788dev/loopdeck',
    'image' => 'ghcr.nju.edu.cn/7788dev/loopdeck:1.1.27',
    'message' => '已更新到 v1.1.27',
    'error' => null,
], JSON_UNESCAPED_SLASHES));

$updater = new SystemUpdater(null, [
    'state_file' => $stateFile,
    'enabled' => true,
    'check_interval_seconds' => 600,
]);
$status = $updater->status();
updaterCheck($status['current_version'] === '1.1.27', 'Status returned the wrong local version');
updaterCheck($status['latest_version'] === '1.1.27', 'State returned the wrong remote version');
updaterCheck($status['update_available'] === false, 'Equal versions were marked as updateable');
updaterCheck($status['updater_available'] === true, 'Configured updater was reported unavailable');
updaterCheck($status['status'] === 'updated', 'Updater status was not loaded');
updaterCheck($status['image_repository'] === 'ghcr.nju.edu.cn/7788dev/loopdeck', 'Selected mirror was not loaded');
updaterCheck($status['check_interval_seconds'] === 600, 'Configured check interval was not retained');

file_put_contents($stateFile, '{not-json');
$invalid = new SystemUpdater(null, ['state_file' => $stateFile]);
updaterCheck($invalid->status()['error'] !== null, 'Invalid state did not produce an error');

$disabled = new SystemUpdater(null, ['state_file' => $stateFile, 'enabled' => false]);
$disabledStatus = $disabled->status();
updaterCheck($disabledStatus['status'] === 'disabled', 'Disabled updater was not reported');
updaterCheck($disabledStatus['updater_available'] === false, 'Disabled updater was reported available');

$adminRoute = file_get_contents(dirname(__DIR__) . '/app/admin/route/app.php');
$adminAjax = file_get_contents(dirname(__DIR__) . '/app/admin/controller/Ajax.php');
$updateView = file_get_contents(dirname(__DIR__) . '/app/admin/view/system/update.html');
updaterCheck(!str_contains((string)$adminRoute, "ajax/update"), 'Manual update route is still registered');
updaterCheck(!str_contains((string)$adminAjax, 'SystemUpdater'), 'Admin Ajax still exposes the updater service');
updaterCheck(!str_contains((string)$updateView, 'system-update-button'), 'Manual update button is still rendered');
updaterCheck(!str_contains((string)$updateView, '/admin/ajax/update'), 'Manual update JavaScript endpoint is still rendered');
updaterCheck(!method_exists(SystemUpdater::class, 'trigger'), 'SystemUpdater still exposes a manual trigger');

@unlink($stateFile);
echo "System updater state tests passed\n";
