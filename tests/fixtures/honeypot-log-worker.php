<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/service/HoneypotLog.php';

$log = new app\service\HoneypotLog($argv[1]);
$accepted = 0;
for ($i = 0; $i < 30; $i++) {
    $accepted += (int)$log->record('blocked', '/index/ajax', 'POST', '198.51.100.2', (int)$argv[2]);
}
echo $accepted;
