#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    echo "用法：loopdeck-db-info [--show-password]\n默认隐藏密码；指定 --show-password 查看完整数据库连接配置。\n";
    exit(0);
}
if ($arguments !== [] && $arguments !== ['--show-password']) {
    fwrite(STDERR, "用法：loopdeck-db-info [--show-password]\n");
    exit(1);
}
$showPassword = $arguments === ['--show-password'];
$appRoot = getenv('APP_BASE_DIR') ?: '/var/www/html';
$configFile = $appRoot . '/config/Db.php';

try {
    if (is_file($configFile)) {
        // Installed sites use this file even when MYSQL_* has since changed.
        // Discard accidental output from a damaged config, including secrets.
        ob_start();
        try {
            $database = (static fn(string $file) => @require $file)($configFile);
        } finally {
            ob_end_clean();
        }
        if (!is_array($database)) {
            throw new UnexpectedValueException('Invalid database configuration');
        }
        $source = 'config/Db.php';
    } elseif (getenv('MYSQL_HOST') !== false) {
        $port = getenv('MYSQL_PORT');
        $database = [
            'hostname' => trim((string)getenv('MYSQL_HOST')),
            'hostport' => $port === false ? '3306' : $port,
            'database' => trim((string)getenv('MYSQL_DATABASE')),
            'username' => trim((string)getenv('MYSQL_USER')),
            'password' => (string)getenv('MYSQL_PASSWORD'),
        ];
        $source = '容器环境变量（首次安装）';
    } else {
        echo "[LoopDeck] 尚未配置数据库，请先完成网页安装。\n";
        exit(0);
    }

    // Match the defaults in config/database.php without booting the application.
    $defaults = ['hostname' => '127.0.0.1', 'hostport' => '3306', 'database' => '', 'username' => 'root', 'password' => ''];
    $fields = ['hostname' => '数据库地址', 'hostport' => '端口', 'database' => '数据库名', 'username' => '用户名'];
    if ($showPassword) {
        $fields['password'] = '密码';
    }

    $lines = ['[LoopDeck] 数据库连接信息（来源：' . $source . '）'];
    foreach ($fields as $field => $label) {
        $value = $database[$field] ?? $defaults[$field];
        if (!is_string($value) && !is_int($value)) {
            throw new UnexpectedValueException('Invalid database connection field');
        }
        // Quoting keeps special characters intact and each field on one log line.
        $lines[] = '[LoopDeck] ' . $label . '：' . json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if (!$showPassword) {
        $lines[] = '[LoopDeck] 密码已隐藏。查看完整配置：docker compose exec app loopdeck-db-info --show-password';
    }
    echo implode("\n", $lines) . "\n";
} catch (Throwable $exception) {
    // Do not put exception details or configuration contents in startup logs.
    fwrite(STDERR, "[LoopDeck] 无法读取数据库连接配置，请检查 config/Db.php 或 MYSQL_* 环境变量。\n");
    exit(1);
}
