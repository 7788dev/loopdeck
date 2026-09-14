<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\middleware\LoadConfigs;

function singleSiteCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$app = new think\App(dirname(__DIR__) . '/');
$app->setRuntimePath(sys_get_temp_dir() . '/loopdeck-single-site-test-' . bin2hex(random_bytes(6)) . '/');
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});

$database = new class {
    public array $tables = [];
    public string $name = 'Primary';

    public function name(string $table): object
    {
        $this->tables[] = $table;
        singleSiteCheck(in_array($table, ['weblist', 'configs'], true), 'Unexpected configuration table');
        $rows = $table === 'weblist'
            ? [['web_id' => 2, 'domain' => 'archived.example', 'webname' => 'Archived', 'prefix' => 'archived_'],
                ['web_id' => 1, 'domain' => 'primary.example', 'webname' => $this->name, 'prefix' => 'unused_']]
            : [['k' => 'mail_name', 'v' => 'primary@example.invalid']];
        return new class($rows) {
            public function __construct(private array $rows) {}

            public function where(string $field, string $operator, mixed $value): self
            {
                singleSiteCheck($operator === '=', 'Unexpected site filter');
                $this->rows = array_filter($this->rows, static fn(array $row): bool => ($row[$field] ?? null) === $value);
                return $this;
            }

            public function find(): ?array
            {
                return array_values($this->rows)[0] ?? null;
            }

            public function select(): array
            {
                return $this->rows;
            }
        };
    }

    public function table(string $name): never
    {
        throw new RuntimeException('Configuration table was selected from a stored site prefix');
    }
};
$cache = new class {
    public array $values = [];
    private array $tags = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function tag(string $tag): object
    {
        return new class($this, $tag) {
            public function __construct(private object $cache, private string $tag) {}
            public function set(string $key, mixed $value, int $ttl): void { $this->cache->save($this->tag, $key, $value); }
            public function clear(): void { $this->cache->clearTag($this->tag); }
        };
    }

    public function save(string $tag, string $key, mixed $value): void
    {
        $this->values[$key] = $value;
        $this->tags[$tag][$key] = true;
    }

    public function clearTag(string $tag): void
    {
        foreach (array_keys($this->tags[$tag] ?? []) as $key) {
            unset($this->values[$key]);
        }
    }
};
$app->instance('think\DbManager', $database);
$app->instance('cache', $cache);
$cache->values['site_row_' . md5('archived.example')] = ['web_id' => 2];
$cache->values['site_settings_1'] = ['mail_name' => 'stale@example.invalid'];
$loadSite = new ReflectionMethod(LoadConfigs::class, 'loadSite');
$loadSettings = new ReflectionMethod(LoadConfigs::class, 'loadSettings');
$middleware = new LoadConfigs();
$previousHost = $_SERVER['HTTP_HOST'] ?? null;
try {
    foreach (['archived.example', 'primary.example', '127.0.0.1:8001'] as $host) {
        $_SERVER['HTTP_HOST'] = $host;
        singleSiteCheck($loadSite->invoke($middleware)['web_id'] === 1, 'Host or legacy cache selected another site');
        singleSiteCheck($loadSettings->invoke($middleware)['mail_name'] === 'primary@example.invalid',
            'Settings came from a legacy per-site table or cache');
    }
    singleSiteCheck($database->tables === ['weblist', 'configs'], 'Configuration cache was not reused');
    $database->name = 'Updated';
    LoadConfigs::invalidate();
    singleSiteCheck($loadSite->invoke($middleware)['webname'] === 'Updated', 'Saving site settings did not invalidate the cache');
    singleSiteCheck($loadSettings->invoke($middleware)['mail_name'] === 'primary@example.invalid', 'Config reload failed');
    singleSiteCheck($database->tables === ['weblist', 'configs', 'weblist', 'configs'], 'Cache invalidation missed a configuration record');
} finally {
    if ($previousHost === null) {
        unset($_SERVER['HTTP_HOST']);
    } else {
        $_SERVER['HTTP_HOST'] = $previousHost;
    }
}

echo "Single-site configuration and cache tests passed\n";
