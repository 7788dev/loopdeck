<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\admin\controller\Ajax;
use app\middleware\LoadConfigs;
use app\service\AgentRouterProxySettings;
use think\facade\Db;

fixtureTable('configs', 'k TEXT PRIMARY KEY, v TEXT');
$ajax = new Ajax();
$secret = 'http://fixture-user:fixture-secret@127.0.0.1:8080';
fixtureRequest(['kind' => 'proxy', 'source' => $secret, 'origin' => 'https://agentrouter.org']);
$saved = $ajax->set('agentrouterProxy')->getData();
functionalCheck($saved['code'] === 1 && !str_contains(json_encode($saved), 'fixture-secret')
    && !str_contains(json_encode($saved), '127.0.0.1'), 'Admin save failed or exposed its private source');
$settings = new AgentRouterProxySettings();
functionalCheck($settings->load()['source'] === $secret && $settings->present()['origin_main'], 'Private settings were not saved');
fixtureRequest(['kind' => 'proxy', 'source' => '', 'origin' => 'https://agentrouter.org']);
functionalCheck($ajax->set('agentrouterProxy')->getData()['code'] === 1 && $settings->load()['source'] === $secret,
    'Admin blank save erased the configured source');
foreach ([AgentRouterProxySettings::SETTINGS_KEY, strtoupper(AgentRouterProxySettings::SETTINGS_KEY),
    AgentRouterProxySettings::STATE_KEY] as $key) {
    fixtureRequest([$key => 'forged-state']);
    functionalCheck($ajax->set('config')->getData()['code'] === 0 && $settings->load()['source'] === $secret,
        'Generic configuration endpoint bypassed proxy validation or lease ownership');
}
fixtureRequest(['kind' => 'subscription', 'source' => 'file:///etc/private', 'origin' => 'https://agentrouter.org']);
functionalCheck($ajax->set('agentrouterProxy')->getData()['code'] === 0 && $settings->load()['source'] === $secret,
    'An invalid subscription damaged existing settings');
Db::name('configs')->insert(['k' => 'regular_setting', 'v' => 'visible-value']);
$cache = new class {
    public array $values = [];
    public function get(string $key) { return $this->values[$key] ?? null; }
    public function tag(string $tag): self { return $this; }
    public function set(string $key, array $value, int $ttl): void { $this->values[$key] = $value; }
};
$app->instance('cache', $cache);
$load = new ReflectionMethod(LoadConfigs::class, 'loadSettings');
$load->setAccessible(true);
foreach ([1, 2] as $round) {
    $public = $load->invoke(new LoadConfigs());
    functionalCheck($public === ['regular_setting' => 'visible-value']
        && !str_contains(serialize($cache->values), 'fixture-secret'), 'Private proxy settings entered shared sys configuration');
}
fixtureRequest(['clear' => '1']);
functionalCheck($ajax->set('agentrouterProxy')->getData()['code'] === 1
    && $settings->load()['source'] === '', 'Explicit admin clear kept the source');
fixtureRequest(['kind' => 'proxy', 'source' => ['nested-secret']]);
functionalCheck($ajax->set('agentrouterProxy')->getData()['code'] === 0, 'Nested proxy input was accepted');

echo "AgentRouter administrator settings, secret retention, shared-config exclusion and private-key guards tests passed\n";
