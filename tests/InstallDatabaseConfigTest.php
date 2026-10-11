<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\install\service\DatabaseConfig;
use app\install\validate\Install;

function installDatabaseCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$cache = sys_get_temp_dir() . '/loopdeck-install-test-' . bin2hex(random_bytes(6)) . '/';
$variables = ['MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'];
$previous = [];
foreach ($variables as $variable) {
    $previous[$variable] = getenv($variable);
    putenv($variable);
}

try {
    $app = new think\App($root . '/');
    $app->setRuntimePath($cache);
    $app->initialize();
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    });
    $manual = [
        'install-db-hostname' => 'manual.example.invalid',
        'install-db-hostport' => '3306',
        'install-db-database' => 'fixture_manual',
        'install-db-username' => 'fixture_manual_user',
        'install-db-password' => 'fixture-manual-password',
        'install-admin-qq' => '10000',
        'install-admin-username' => 'fixture_admin',
        'install-admin-password' => 'fixture-admin-password',
        'install-admin-password-confirm' => 'fixture-admin-password',
    ];
    installDatabaseCheck(DatabaseConfig::environmentInput() === null, 'Plain PHP install must allow manual database settings');
    installDatabaseCheck(DatabaseConfig::resolveInput($manual) === $manual, 'Manual database values were changed');

    putenv('MYSQL_HOST=db');
    putenv('MYSQL_DATABASE=fixture_automatic');
    putenv('MYSQL_USER=fixture_automatic_user');
    putenv('MYSQL_PASSWORD=fixture-$password#with=characters');
    $resolved = DatabaseConfig::resolveInput($manual);
    installDatabaseCheck($resolved['install-db-hostname'] === 'db', 'Browser input overrode the configured host');
    installDatabaseCheck($resolved['install-db-hostport'] === '3306', 'Missing automatic port must default to 3306');
    installDatabaseCheck($resolved['install-db-database'] === 'fixture_automatic', 'Browser input overrode the configured database');
    installDatabaseCheck($resolved['install-db-username'] === 'fixture_automatic_user', 'Browser input overrode the configured user');
    installDatabaseCheck($resolved['install-db-password'] === 'fixture-$password#with=characters', 'Automatic password was changed');
    installDatabaseCheck($resolved['install-admin-password'] === $manual['install-admin-password'], 'Admin credentials were changed');

    $administrator = array_filter($manual, static fn(string $key): bool => str_starts_with($key, 'install-admin-'), ARRAY_FILTER_USE_KEY);
    installDatabaseCheck((new Install())->scene('install')->check(DatabaseConfig::resolveInput($administrator)), 'An admin-only form cannot install with container settings');

    putenv('MYSQL_HOST=cloud.example.invalid');
    putenv('MYSQL_PORT=13306');
    $resolved = DatabaseConfig::resolveInput($administrator);
    installDatabaseCheck($resolved['install-db-hostname'] === 'cloud.example.invalid' && $resolved['install-db-hostport'] === '13306', 'External database settings were ignored');

    $savedDatabase = [
        'hostname' => 'existing-db.example.invalid',
        'hostport' => 3308,
        'database' => 'fixture_existing_database',
        'username' => 'fixture_existing_user',
        'password' => 'fixture-existing-password',
    ];
    if (!is_dir($cache)) {
        mkdir($cache, 0700, true);
    }
    file_put_contents($cache . 'Db.php', '<?php return ' . var_export($savedDatabase, true) . ';');
    $app->config->load($cache . 'Db.php', 'Db');
    $runtimeDatabase = (require $root . '/config/database.php')['connections']['mysql'];
    foreach ($savedDatabase as $key => $value) {
        installDatabaseCheck($runtimeDatabase[$key] === $value, 'Automatic install settings overrode an installed database: ' . $key);
    }

    putenv('MYSQL_DATABASE');
    $incomplete = DatabaseConfig::resolveInput($manual);
    installDatabaseCheck($incomplete['install-db-database'] === '', 'Incomplete environment fell back to a browser database');
    installDatabaseCheck(!(new Install())->scene('install')->check($incomplete), 'Incomplete automatic configuration was accepted');
    putenv('MYSQL_DATABASE=fixture_automatic');

    $engine = new think\Template([
        'view_path' => $root . '/app/install/view/',
        'cache_path' => $cache . 'templates/',
    ]);
    foreach ([true, false] as $automatic) {
        foreach ([true, false] as $tokenRequired) {
            ob_start();
            try {
                $engine->fetch('index/install', [
                    'database_auto_configured' => $automatic,
                    'install_token_required' => $tokenRequired,
                ]);
                $html = (string)ob_get_contents();
            } finally {
                ob_end_clean();
            }
            installDatabaseCheck(str_contains($html, 'name="install-admin-username"'), 'Administrator form is missing');
            installDatabaseCheck(str_contains($html, 'name="install-admin-password-confirm"'), 'Administrator password confirmation is missing');
            installDatabaseCheck(str_contains($html, 'name="install-db-password"') === !$automatic, 'Database fields are shown in the wrong installation mode');
            installDatabaseCheck(str_contains($html, 'name="install-token"') === $tokenRequired, 'Install token field is shown in the wrong installation mode');
            if ($automatic) {
                installDatabaseCheck(!str_contains($html, 'name="install-db-'), 'Automatic installation still submits database inputs');
                installDatabaseCheck(str_contains($html, '数据库连接已自动配置'), 'Automatic installation instructions are missing');
            }
            installDatabaseCheck(!str_contains($html, 'fixture-$password#with=characters'), 'Server database password leaked into the page');
        }
    }

    // The setup token is read from the environment and never leaks into the
    // rendered page: the operator receives it out-of-band (deploy.sh output).
    $controllerClass = new ReflectionClass(\app\install\controller\Index::class);
    $tokenReader = $controllerClass->getMethod('installToken');
    $tokenReader->setAccessible(true);
    $verifier = $controllerClass->getMethod('verifyInstallToken');
    $verifier->setAccessible(true);
    $controller = $controllerClass->newInstanceWithoutConstructor();
    putenv('INSTALL_TOKEN');
    installDatabaseCheck($tokenReader->invoke($controller) === '', 'INSTALL_TOKEN was set without configuration');
    installDatabaseCheck($verifier->invoke($controller) === null, 'Installer demanded a token that was never configured');
    putenv('INSTALL_TOKEN=fixture-install-token-123');
    $missing = $verifier->invoke($controller);
    installDatabaseCheck($missing !== null && (int)($missing->getData()['code'] ?? 0) === -1, 'Installer accepted a POST without the required setup token');
    putenv('INSTALL_TOKEN');
    installDatabaseCheck(!str_contains(json_encode($missing->getData(), JSON_UNESCAPED_UNICODE), 'fixture-install-token-123'), 'The expected token value leaked into the refusal message');

    // The reinstall guard must be part of the flow: the schema check runs on
    // the live connection before install.sql is imported.
    $controllerSource = file_get_contents($root . '/app/install/controller/Index.php');
    installDatabaseCheck(
        str_contains($controllerSource, 'schemaAlreadyPresent')
            && strpos($controllerSource, 'schemaAlreadyPresent($connection)') < strpos($controllerSource, 'importSchema($connection)'),
        'Installer does not refuse databases that already contain LoopDeck tables'
    );

    echo "Install database configuration and rendered form tests passed\n";
} finally {
    foreach ($previous as $variable => $value) {
        putenv($value === false ? $variable : $variable . '=' . $value);
    }
    if (is_dir($cache)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($cache);
    }
}
