<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function databaseInfoCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{code:int,output:string} */
function databaseInfoRun(string $project, array $environment = [], array $arguments = []): array
{
    $script = dirname(__DIR__) . '/docker/database-info.php';
    if (!is_file($script)) {
        $script = '/usr/local/bin/loopdeck-db-info';
    }
    $variables = getenv();
    foreach (array_keys($variables) as $key) {
        if (str_starts_with($key, 'MYSQL_')) {
            unset($variables[$key]);
        }
    }
    $variables = array_replace($variables, $environment, ['APP_BASE_DIR' => $project]);
    $process = proc_open([PHP_BINARY, $script, ...$arguments], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $project, $variables);
    databaseInfoCheck(is_resource($process), 'Cannot start database information command');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => $output];
}

function databaseInfoField(string $output, string $label): string
{
    $prefix = '[LoopDeck] ' . $label . '：';
    foreach (explode("\n", $output) as $line) {
        if (str_starts_with($line, $prefix)) {
            return json_decode(substr($line, strlen($prefix)), true, 16, JSON_THROW_ON_ERROR);
        }
    }
    throw new RuntimeException('Database information field is missing: ' . $label);
}

$temporaryRoot = sys_get_temp_dir() . '/loopdeck-db-info-test-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot . '/config', 0700, true);
$configFile = $temporaryRoot . '/config/Db.php';
$environment = [
    'MYSQL_HOST' => 'db',
    'MYSQL_DATABASE' => 'fixture_generated',
    'MYSQL_USER' => 'fixture_generated_user',
    'MYSQL_PASSWORD' => "fixture-\$password#with='characters'\\and\nnewline",
    'MYSQL_ROOT_PASSWORD' => 'fixture-root-secret-never-displayed',
    'CRON_KEY' => 'fixture-cron-secret-never-displayed',
];

try {
    $missing = databaseInfoRun($temporaryRoot);
    databaseInfoCheck($missing['code'] === 0 && str_contains($missing['output'], '尚未配置数据库'), 'Legacy uninstalled site did not receive installation guidance');

    $before = scandir($temporaryRoot . '/config');
    $hidden = databaseInfoRun($temporaryRoot, $environment);
    databaseInfoCheck($hidden['code'] === 0, 'Environment connection information could not be read');
    databaseInfoCheck(databaseInfoField($hidden['output'], '数据库地址') === 'db'
        && databaseInfoField($hidden['output'], '端口') === '3306'
        && databaseInfoField($hidden['output'], '数据库名') === $environment['MYSQL_DATABASE']
        && databaseInfoField($hidden['output'], '用户名') === $environment['MYSQL_USER'], 'Startup information did not match the generated database configuration');
    databaseInfoCheck(str_contains($hidden['output'], 'loopdeck-db-info --show-password'), 'Startup did not show the explicit password retrieval command');
    databaseInfoCheck(!str_contains($hidden['output'], 'fixture-$password') && !str_contains($hidden['output'], '密码：'), 'Startup output exposed the database password');
    databaseInfoCheck(scandir($temporaryRoot . '/config') === $before, 'Inspecting database information wrote configuration');

    $visible = databaseInfoRun($temporaryRoot, $environment, ['--show-password']);
    databaseInfoCheck($visible['code'] === 0 && databaseInfoField($visible['output'], '密码') === $environment['MYSQL_PASSWORD'], 'Explicit password retrieval changed special characters');
    foreach ([$hidden, $visible] as $result) {
        databaseInfoCheck(!str_contains($result['output'], $environment['MYSQL_ROOT_PASSWORD'])
            && !str_contains($result['output'], $environment['CRON_KEY']), 'Database information exposed unrelated secrets');
    }
    databaseInfoCheck(databaseInfoRun($temporaryRoot, $environment)['output'] === $hidden['output'], 'Explicit password retrieval changed later startup logging');

    $environment['MYSQL_HOST'] = 'external.example.invalid';
    $environment['MYSQL_PORT'] = '13306';
    $external = databaseInfoRun($temporaryRoot, $environment);
    databaseInfoCheck(databaseInfoField($external['output'], '数据库地址') === $environment['MYSQL_HOST']
        && databaseInfoField($external['output'], '端口') === '13306', 'External database endpoint was not reported');

    $saved = [
        'hostname' => 'saved.example.invalid',
        'hostport' => 3308,
        'database' => 'fixture_saved',
        'username' => 'fixture_saved_user',
        'password' => 'fixture-saved-password',
    ];
    file_put_contents($configFile, '<?php return ' . var_export($saved, true) . ';');
    $fingerprint = hash_file('sha256', $configFile);
    foreach ([$environment, []] as $legacyEnvironment) {
        $installed = databaseInfoRun($temporaryRoot, $legacyEnvironment);
        databaseInfoCheck($installed['code'] === 0 && str_contains($installed['output'], 'config/Db.php'), 'Installed configuration was not used after an image upgrade');
        databaseInfoCheck(databaseInfoField($installed['output'], '数据库地址') === $saved['hostname']
            && databaseInfoField($installed['output'], '端口') === '3308'
            && databaseInfoField($installed['output'], '数据库名') === $saved['database']
            && databaseInfoField($installed['output'], '用户名') === $saved['username'], 'Environment values overrode the installed database information');
        databaseInfoCheck(!str_contains($installed['output'], $saved['password']), 'Saved password leaked on startup');
        $installedVisible = databaseInfoRun($temporaryRoot, $legacyEnvironment, ['--show-password']);
        databaseInfoCheck(databaseInfoField($installedVisible['output'], '密码') === $saved['password'], 'Command showed a stale environment password');
        databaseInfoCheck(hash_file('sha256', $configFile) === $fingerprint, 'Database diagnostics modified the installed configuration');
    }

    $saved['username'] = "user\n[LoopDeck] forged\r\x1b[31m";
    file_put_contents($configFile, '<?php return ' . var_export($saved, true) . ';');
    $escaped = databaseInfoRun($temporaryRoot, [], ['--show-password']);
    databaseInfoCheck($escaped['code'] === 0 && databaseInfoField($escaped['output'], '用户名') === $saved['username'], 'Control characters were not preserved in quoted output');
    databaseInfoCheck(!str_contains($escaped['output'], "\n[LoopDeck] forged") && !str_contains($escaped['output'], "\x1b"), 'Configuration injected terminal control characters or forged log lines');

    foreach ([
        '<?php return "fixture-invalid-secret";',
        '<?php return ["hostname" => ["fixture-invalid-secret"]];',
        '<?php echo "fixture-invalid-secret"; throw new RuntimeException("fixture-invalid-secret");',
        '<?php return ["hostname" => "fixture-invalid-secret";',
    ] as $invalidConfig) {
        file_put_contents($configFile, $invalidConfig);
        foreach ([[], ['--show-password']] as $arguments) {
            $invalid = databaseInfoRun($temporaryRoot, $environment, $arguments);
            databaseInfoCheck($invalid['code'] !== 0 && str_contains($invalid['output'], '无法读取数据库连接配置'), 'Invalid saved configuration was silently replaced by environment values');
            databaseInfoCheck(!str_contains($invalid['output'], 'fixture-invalid-secret')
                && !str_contains($invalid['output'], 'fixture-$password'), 'A configuration failure leaked sensitive contents');
        }
    }

    foreach ([['--help'], ['--invalid'], ['--show-password', '--invalid']] as $arguments) {
        $usage = databaseInfoRun($temporaryRoot, $environment, $arguments);
        databaseInfoCheck($usage['code'] === ($arguments === ['--help'] ? 0 : 1)
            && str_contains($usage['output'], '用法：'), 'Unexpected command-line argument handling');
        databaseInfoCheck(!str_contains($usage['output'], 'fixture-'), 'Help or invalid arguments read sensitive configuration');
    }

    echo "Database information tests passed: redacted logs, explicit secrets, saved configuration and failure isolation\n";
} finally {
    if (is_file($configFile)) {
        unlink($configFile);
    }
    rmdir($temporaryRoot . '/config');
    rmdir($temporaryRoot);
}
