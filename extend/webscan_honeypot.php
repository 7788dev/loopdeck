<?php

declare(strict_types=1);

/** Normal requests only compare routes; no log, session or framework is loaded. */
function webscan_honeypot_route(): void
{
    $route = webscan_route_path();
    $kind = match ($route) {
        '/api/debug/config' => 'decoy_config',
        '/api/backup/export' => 'decoy_backup',
        default => null,
    };
    if ($kind === null) {
        return;
    }
    try {
        require_once __DIR__ . '/../app/service/Honeypot.php';
        \app\service\Honeypot::respond($kind, $route);
    } catch (\Throwable) {
        webscan_pape();
        exit;
    }
}

/** Every original rejection remains terminal, including any decoy failure. */
function webscan_honeypot_reject(string $value = '', string $source = ''): never
{
    try {
        require_once __DIR__ . '/../app/service/Honeypot.php';
        if (\app\service\Honeypot::enabled()) {
            $route = webscan_route_path();
            if (\app\service\Honeypot::isSqlExtraction($value, $source)) {
                \app\service\Honeypot::respond('sql_result', $route);
            }
            \app\service\Honeypot::record('blocked', $route);
        }
    } catch (\Throwable) {
        // Keep the existing terminal 403 even if the optional layer fails.
    }
    webscan_pape();
    exit;
}
