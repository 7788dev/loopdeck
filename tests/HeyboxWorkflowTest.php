<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new class('42', 'fixture-pkey') extends xiaoheihe\BlackBox {
    public mixed $response = null;
    public array $last = [];
    public function curl(string $method, string $url, string $urlpath = '', array $params = [], string $cookie = '', array $header = [], bool $json = true, bool $multipart = false)
    {
        $this->last = compact('method', 'url', 'params', 'cookie');
        return $this->response;
    }
};
function heyboxCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$client->response = ['status' => 'ok', 'result' => ['sign_in_streak' => 3, 'sign_in_coin' => 5, 'sign_in_exp' => 10]];
heyboxCheck($client->sign()['code'] === 1, 'Successful check-in rejected');
heyboxCheck(!str_contains($client->last['url'], 'pkey'), 'Credential leaked into URL');
$client->response = false;
heyboxCheck($client->sign()['code'] === 0 && !$client->cookiezt, 'Network error invalidated account');
$client->response = ['status' => 'failed', 'msg' => '登录已过期'];
heyboxCheck($client->sign()['code'] === 0 && $client->cookiezt, 'Expired credentials were not surfaced');
heyboxCheck((new xiaoheihe\BlackBox('', ''))->sign()['code'] === 0, 'Empty credential accepted');
echo "Heybox success, upstream failure and credential tests passed\n";
