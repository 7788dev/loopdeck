<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function deploymentCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,string> */
function deploymentValues(string $project): array
{
    $values = [];
    foreach (file($project . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $match)) {
            $values[$match[1]] = $match[2];
        }
    }
    return $values;
}

/** @return array{code:int,output:string,calls:string} */
function deploymentRun(string $project, array $environment = [], bool $prepareOnly = false): array
{
    $command = [getenv('LOOPDECK_TEST_SHELL') ?: 'sh', $project . '/run.sh', $project];
    if ($prepareOnly) {
        $command[] = '--prepare-only';
    }
    $environment = array_replace(getenv(), [
        'TUNE_CPU_COUNT' => '2',
        'TUNE_MEMORY_MB' => '2048',
        'LOOPDECK_TEST_COMPOSE_VERSION' => '2.20.3',
        'LOOPDECK_TEST_EXISTING_VOLUME' => '',
        'LOOPDECK_TEST_CONFIG_EXIT' => '0',
    ], $environment);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $environment);
    deploymentCheck(is_resource($process), 'Cannot start deployment fixture; on Windows set LOOPDECK_TEST_SHELL to Git for Windows bin/sh.exe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'code' => proc_close($process),
        'output' => $output,
        'calls' => is_file($project . '/docker-calls') ? (string)file_get_contents($project . '/docker-calls') : '',
    ];
}

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . '/loopdeck-deploy-test-' . bin2hex(random_bytes(6));
$createProject = static function (string $name, ?string $env = null) use ($root, $temporaryRoot): string {
    $project = $temporaryRoot . '/' . $name . ' project';
    mkdir($project . '/docker', 0700, true);
    mkdir($project . '/bin', 0700, true);
    foreach (['docker/deploy.sh', 'docker/tune-env.sh', '.env.example', 'compose.yaml'] as $file) {
        deploymentCheck(is_file($root . '/' . $file), 'Missing deployment test source: ' . $file);
        file_put_contents($project . '/' . $file, str_replace("\r\n", "\n", (string)file_get_contents($root . '/' . $file)));
    }
    if ($env !== null) {
        file_put_contents($project . '/.env', $env);
    }
    file_put_contents($project . '/run.sh', <<<'SH'
#!/bin/sh
set -eu
cd "$1"
shift
export PATH="$PWD/bin:$PATH"
export LOOPDECK_TEST_COMMAND_LOG="$PWD/docker-calls"
: > "$LOOPDECK_TEST_COMMAND_LOG"
exec sh docker/deploy.sh "$@"
SH);
    file_put_contents($project . '/bin/docker', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$LOOPDECK_TEST_COMMAND_LOG"
case "$*" in
    'compose version --short') printf '%s\n' "$LOOPDECK_TEST_COMPOSE_VERSION" ;;
    'info') exit 0 ;;
    'volume ls '*) printf '%s' "${LOOPDECK_TEST_EXISTING_VOLUME:-}" ;;
    'compose --env-file .env config --quiet')
        if [ "${MYSQL_HOST+x}" = x ] || [ "${COMPOSE_PROFILES+x}" = x ]; then
            echo 'Inherited database configuration reached Compose' >&2
            exit 99
        fi
        exit "$LOOPDECK_TEST_CONFIG_EXIT"
        ;;
    'compose --env-file .env pull'|'compose --env-file .env up --no-build --wait --wait-timeout 180') exit 0 ;;
    *) echo "Unexpected Docker invocation: $*" >&2; exit 90 ;;
esac
SH);
    chmod($project . '/bin/docker', 0700);
    return $project;
};

try {
    $project = $createProject('fresh');
    $first = deploymentRun($project, ['MYSQL_HOST' => 'unexpected.example.invalid', 'COMPOSE_PROFILES' => 'unexpected']);
    deploymentCheck($first['code'] === 0, 'Fresh deployment failed: ' . $first['output']);
    $values = deploymentValues($project);
    foreach (['MYSQL_DATABASE' => '/^loopdeck_[a-f0-9]{12}$/', 'MYSQL_USER' => '/^ld_[a-f0-9]{16}$/', 'MYSQL_PASSWORD' => '/^[a-f0-9]{48}$/', 'MYSQL_ROOT_PASSWORD' => '/^[a-f0-9]{64}$/', 'CRON_KEY' => '/^[a-f0-9]{96}$/'] as $key => $pattern) {
        deploymentCheck(preg_match($pattern, $values[$key] ?? '') === 1, 'Missing or invalid generated ' . $key);
        deploymentCheck(!str_contains($first['output'], $values[$key]), 'Generated configuration leaked in deployment output');
    }
    deploymentCheck($values['MYSQL_HOST'] === 'db' && $values['MYSQL_PORT'] === '3306', 'Default internal database endpoint is wrong');
    deploymentCheck($values['COMPOSE_PROFILES'] === 'local-db', 'Fresh deployment did not enable the bundled database');
    deploymentCheck(str_contains($first['calls'], 'config --quiet') && str_contains($first['calls'], 'up --no-build --wait'), 'Deployment did not validate and start Compose');
    if (PHP_OS_FAMILY !== 'Windows') {
        deploymentCheck((fileperms($project . '/.env') & 0777) === 0600, 'Generated credentials are not private');
    }
    $second = deploymentRun($project, ['LOOPDECK_TEST_EXISTING_VOLUME' => 'fixture_db_data']);
    deploymentCheck($second['code'] === 0 && deploymentValues($project) === $values, 'Repeat deployment changed saved configuration');

    $other = $createProject('other');
    deploymentCheck(deploymentRun($other, [], true)['code'] === 0, 'Prepare-only deployment failed');
    $otherValues = deploymentValues($other);
    foreach (['MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'CRON_KEY'] as $key) {
        deploymentCheck($otherValues[$key] !== $values[$key], 'Independent installations shared generated ' . $key);
    }
    deploymentCheck(!str_contains((string)file_get_contents($other . '/docker-calls'), ' pull'), 'Prepare-only mode pulled images');

    $legacyEnv = "MYSQL_DATABASE=loopdeck\nMYSQL_USER=loopdeck\nMYSQL_PASSWORD=replace_with_a_random_password\nMYSQL_ROOT_PASSWORD=replace_with_another_random_password\nCRON_KEY=replace_with_a_long_random_cron_key\n";
    $legacy = $createProject('legacy', $legacyEnv);
    deploymentCheck(deploymentRun($legacy)['code'] === 0, 'Legacy example configuration was not upgraded');
    $legacyValues = deploymentValues($legacy);
    deploymentCheck($legacyValues['MYSQL_DATABASE'] === 'loopdeck' && $legacyValues['MYSQL_USER'] === 'loopdeck', 'Legacy database identifiers were replaced');
    deploymentCheck(!str_contains((string)file_get_contents($legacy . '/.env'), 'replace_with_'), 'Legacy placeholder secrets were retained');

    $externalEnv = <<<'ENV'
MYSQL_HOST=db
MYSQL_HOST="rds.example.invalid" # last assignment selects the external host
MYSQL_PORT='13306' # custom cloud port
MYSQL_DATABASE=fixture_cloud
MYSQL_USER=fixture_cloud_user
MYSQL_PASSWORD='fixture-$password#with=characters'
CRON_KEY=fixture_custom_cron_key_with_32_characters
COMPOSE_PROFILES=metrics,local-db
ENV;
    $external = $createProject('external', $externalEnv);
    $result = deploymentRun($external);
    deploymentCheck($result['code'] === 0, 'External deployment failed: ' . $result['output']);
    $externalValues = deploymentValues($external);
    deploymentCheck($externalValues['COMPOSE_PROFILES'] === 'metrics', 'External mode did not remove only the bundled database profile');
    deploymentCheck($externalValues['MYSQL_PASSWORD'] === "'fixture-\$password#with=characters'", 'Quoted custom password was changed');
    deploymentCheck(!isset($externalValues['MYSQL_ROOT_PASSWORD']), 'External mode generated an unused root password');
    deploymentCheck(!str_contains($result['calls'], 'volume ls'), 'External mode inspected bundled database volumes');

    foreach (['MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'] as $missing) {
        $incompleteEnv = preg_replace('/^' . $missing . '=.*$/m', $missing . '=', $externalEnv);
        $incomplete = $createProject('missing-' . $missing, $incompleteEnv);
        $result = deploymentRun($incomplete);
        deploymentCheck($result['code'] !== 0 && str_contains($result['output'], $missing), 'Missing external configuration was accepted: ' . $missing);
        deploymentCheck(!str_contains($result['calls'], ' pull') && !str_contains($result['calls'], ' up '), 'Incomplete external configuration started containers');
    }

    foreach (['0', '65536', 'invalid', '3307'] as $port) {
        $invalid = $createProject('port-' . $port, "MYSQL_HOST=db\nMYSQL_PORT=$port\n");
        deploymentCheck(deploymentRun($invalid)['code'] !== 0, 'Invalid bundled database port was accepted: ' . $port);
    }

    $lost = $createProject('lost-config');
    $result = deploymentRun($lost, ['LOOPDECK_TEST_EXISTING_VOLUME' => 'fixture_existing_db_data']);
    deploymentCheck($result['code'] !== 0 && str_contains($result['output'], '请恢复原 .env'), 'Missing credentials for an existing database were regenerated');
    deploymentCheck((deploymentValues($lost)['MYSQL_PASSWORD'] ?? '') === '', 'New credentials were written for an existing database');
    deploymentCheck(!str_contains($result['calls'], ' up '), 'Deployment touched an existing database with missing credentials');

    $oldCompose = $createProject('old-compose');
    $result = deploymentRun($oldCompose, ['LOOPDECK_TEST_COMPOSE_VERSION' => '2.19.1']);
    deploymentCheck($result['code'] !== 0 && !is_file($oldCompose . '/.env'), 'Unsupported Compose version was accepted');
    $failedConfig = $createProject('invalid-compose');
    $result = deploymentRun($failedConfig, ['LOOPDECK_TEST_CONFIG_EXIT' => '1']);
    deploymentCheck($result['code'] !== 0 && !str_contains($result['calls'], ' pull'), 'Deployment continued after Compose validation failed');

    echo "Docker deployment generation, reuse, external database and recovery tests passed\n";
} finally {
    if (is_dir($temporaryRoot)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($temporaryRoot);
    }
}
