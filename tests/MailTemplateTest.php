<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function config(string $name, mixed $default = null): mixed { return $name === 'web.webname' ? 'QA' : $default; }
require_once dirname(__DIR__) . '/app/common.php';

$user = new class {
    public function toArray(): array
    {
        return ['nickname' => '测试用户', 'password' => 'fixture-secret-password', 'sid' => 'fixture-secret-session'];
    }
    public function __toString(): string { return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE); }
};
$message = get_mail_tempale(2, $user, 'https://qa.example/reset');
if (!str_contains($message, '测试用户') || str_contains($message, 'fixture-secret')) {
    throw new RuntimeException('The mail greeting serialized private user fields');
}

echo "Mail template field isolation tests passed\n";
