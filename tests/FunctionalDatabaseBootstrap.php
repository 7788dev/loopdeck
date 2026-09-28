<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/app/common.php';

use think\facade\Db;

// No application initialization, .env, installed database or upstream network.
if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Functional tests require pdo_sqlite (Windows: php -d extension=pdo_sqlite)');
}
$app = new think\App(dirname(__DIR__) . '/');
$app->config->set(['default' => 'fixture', 'auto_timestamp' => false, 'connections' => [
    'fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'qa_', 'fields_strict' => true],
]], 'database');
$database = new think\DbManager();
$database->setConfig($app->config->get('database'));
$app->instance('db', $database);
(new think\service\ModelService($app))->boot();
defined('WEB_ID') || define('WEB_ID', 1);

function functionalCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureTable(string $name, string $columns): void
{
    Db::execute('CREATE TABLE qa_' . $name . ' (' . $columns . ')');
}

function fixtureRequest(array $post = [], array $get = []): void
{
    $request = new class($post, $get) extends think\Request {
        public function __construct(array $post, array $get)
        {
            parent::__construct();
            $this->post = $post;
            $this->get = $get;
            $this->server = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'panel.example'];
        }
    };
    think\Container::getInstance()->instance('request', $request);
}

fixtureTable('users', 'uid INTEGER PRIMARY KEY, web_id INTEGER DEFAULT 1, username TEXT, password TEXT,
    nickname TEXT, qq TEXT, mail TEXT, state INTEGER DEFAULT 1, power INTEGER DEFAULT 0,
    sid TEXT, money DECIMAL DEFAULT 0, quota INTEGER DEFAULT 0, vip_start TEXT, vip_end TEXT,
    login_ip TEXT, login_city TEXT, login_time INTEGER');
Db::name('users')->insert(['uid' => 1, 'username' => 'fixture', 'sid' => 'fixture-session', 'money' => 100, 'quota' => 10]);
think\facade\Session::set('user', Db::name('users')->find(1));

