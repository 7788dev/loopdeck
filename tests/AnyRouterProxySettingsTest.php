<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\admin\controller\Ajax;
use app\middleware\LoadConfigs;
use app\service\AnyRouterProxySettings;
use app\service\TaskExecutionLock;
use think\facade\Db;

fixtureTable('configs', 'k TEXT PRIMARY KEY, v TEXT');
$ajax = new Ajax();
$secret = 'http://fixture-user:fixture-secret@127.0.0.1:8080';
fixtureRequest(['kind' => 'proxy', 'source' => $secret, 'clear' => '']);
$response = $ajax->set('anyrouterProxy');
functionalCheck($response instanceof \think\response\Json, 'AnyRouter administrator proxy settings endpoint is missing');
$saved = $response->getData();
functionalCheck($saved['code'] === 1, 'AnyRouter administrator proxy configuration could not be saved');
functionalCheck(!str_contains(json_encode($saved), 'fixture-secret')
    && !str_contains(json_encode($saved), '127.0.0.1'), 'Admin save exposed a private proxy');

$settings = new AnyRouterProxySettings();
functionalCheck($settings->load()['source'] === $secret && $settings->present()['configured'],
    'The administrator source was not stored privately');
foreach (['', '0', 0] as $clear) {
    fixtureRequest(['kind' => 'proxy', 'source' => '', 'clear' => $clear]);
    functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 1 && $settings->load()['source'] === $secret,
        'An unchecked clear field erased or rejected the saved proxy');
}
foreach (['on', '2', true, false, ['1']] as $clear) {
    fixtureRequest(['kind' => 'proxy', 'source' => $secret, 'clear' => $clear]);
    functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 0 && $settings->load()['source'] === $secret,
        'An invalid clear option altered the existing proxy');
}
fixtureRequest(['kind' => 'subscription', 'source' => '']);
functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 0 && $settings->load()['source'] === $secret,
    'Switching to an empty subscription changed working settings');

$subscription = 'https://subscription.example.invalid/list?token=fixture-subscription-secret';
fixtureRequest(['kind' => 'subscription', 'source' => $subscription, 'clear' => '']);
functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 1 && $settings->load()['source'] === $subscription,
    'A valid subscription could not be saved');
functionalCheck(!str_contains(json_encode($settings->present()), 'fixture-subscription-secret'),
    'The settings projection exposed a subscription token');
$before = $settings->load();
foreach ([['kind' => 'subscription', 'source' => 'file:///etc/private'],
    ['kind' => 'proxy', 'source' => ['nested-secret']], ['kind' => 'unknown'],
    ['kind' => 'builtin', 'http_sources' => '', 'socks5_sources' => ''],
    ['kind' => 'builtin', 'http_sources' => 'https://127.0.0.1/list'],
    ['kind' => 'builtin', 'http_sources' => ['https://8.8.8.8/list']],
    ['kind' => 'builtin', 'http_sources' => 'file:///etc/list']] as $input) {
    fixtureRequest($input);
    functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 0 && $settings->load() === $before,
        'Invalid input changed the saved source or built-in lists');
}
foreach ([AnyRouterProxySettings::SETTINGS_KEY, strtoupper(AnyRouterProxySettings::SETTINGS_KEY)] as $key) {
    fixtureRequest([$key => 'forged-source']);
    functionalCheck($ajax->set('config')->getData()['code'] === 0 && $settings->load() === $before,
        'Generic settings bypassed the private proxy validation');
}
functionalCheck(TaskExecutionLock::acquire(AnyRouterProxySettings::LOCK), 'Cannot prepare an occupied pool lease');
try {
    fixtureRequest(['kind' => 'proxy', 'source' => $secret]);
    functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 0 && $settings->load() === $before,
        'The administrator replaced a source during an active connection');
} finally {
    TaskExecutionLock::release(AnyRouterProxySettings::LOCK);
}

fixtureRequest(['kind' => 'builtin', 'http_sources' => "https://8.8.8.8/http.txt\nhttps://8.8.8.8/http.txt",
    'socks5_sources' => 'https://1.1.1.1/socks.txt', 'clear' => '']);
functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 1, 'Editing the built-in proxy list failed');
$builtin = $settings->load();
functionalCheck($builtin['kind'] === 'builtin' && $builtin['source'] === ''
    && $builtin['sources'] === ['http' => ['https://8.8.8.8/http.txt'], 'socks5h' => ['https://1.1.1.1/socks.txt']],
    'Built-in list editing did not deduplicate URLs or clear the prior private source');
functionalCheck($settings->present()['http_sources'] === 'https://8.8.8.8/http.txt', 'Editable list URLs were not displayed');

Db::name('configs')->insert(['k' => 'regular_setting', 'v' => 'visible-value']);
Db::name('configs')->insert(['k' => 'ANYROUTER_PRIVATE.future', 'v' => 'fixture-private-record']);
$cache = new class {
    public array $values = [];
    public function get(string $key) { return $this->values[$key] ?? null; }
    public function tag(string $tag): self { return $this; }
    public function set(string $key, array $value, int $ttl): void { $this->values[$key] = $value; }
};
$app->instance('cache', $cache);
$load = new ReflectionMethod(LoadConfigs::class, 'loadSettings');
foreach ([1, 2] as $round) {
    $public = $load->invoke(new LoadConfigs());
    functionalCheck($public === ['regular_setting' => 'visible-value'], 'AnyRouter private settings entered shared sys');
}
$cache->values['site_settings_primary']['anyrouter_private.cached'] = 'fixture-private-cache';
functionalCheck($load->invoke(new LoadConfigs()) === ['regular_setting' => 'visible-value'],
    'A stale shared cache exposed private proxy settings');

fixtureRequest(['kind' => 'subscription', 'source' => $subscription]);
$ajax->set('anyrouterProxy');
fixtureRequest(['clear' => '1']);
functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 1 && $settings->load()['kind'] === 'builtin'
    && $settings->load()['source'] === '' && $settings->load()['sources'] === $builtin['sources'],
    'Explicit clearing erased editable built-in lists or retained the private subscription');
fixtureRequest(['kind' => 'builtin', 'http_sources' => implode("\n", AnyRouterProxySettings::defaults()['sources']['http']),
    'socks5_sources' => implode("\n", AnyRouterProxySettings::defaults()['sources']['socks5h'])]);
functionalCheck($ajax->set('anyrouterProxy')->getData()['code'] === 1
    && $settings->load()['sources'] === AnyRouterProxySettings::defaults()['sources'], 'Restoring built-in sources failed');

$environment = getenv('ANYROUTER_PLATFORM_PROXY');
try {
    putenv('ANYROUTER_PLATFORM_PROXY=' . $secret);
    functionalCheck($settings->load()['kind'] === 'builtin', 'Legacy environment overrode an administrator choice');
    Db::name('configs')->where('k', AnyRouterProxySettings::SETTINGS_KEY)->delete();
    functionalCheck($settings->load()['kind'] === 'proxy' && $settings->load()['source'] === $secret,
        'An existing deployment lost its explicitly configured legacy fixed proxy');
} finally {
    putenv($environment === false ? 'ANYROUTER_PLATFORM_PROXY' : 'ANYROUTER_PLATFORM_PROXY=' . $environment);
}

echo "AnyRouter administrator sources, secret retention, validation, lease and shared-config privacy tests passed\n";
