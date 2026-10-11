<?php
declare(strict_types=1);

/**
 * Regression tests for the notification schema in the installation seed.
 *
 * The notification tables used to exist only as runtime `ensureSchema()`
 * DDL, so a fresh installation shipped an incomplete schema and a MySQL
 * user without CREATE rights broke the whole notification subsystem on
 * first use. install.sql must define every table, column and index the
 * repository creates at runtime.
 */

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use app\service\NotificationRepository;

$app->config->set(['connections' => ['mysql' => ['type' => 'mysql', 'prefix' => 'cloud_']]], 'database');

$installSql = file_get_contents(dirname(__DIR__) . '/app/install/install.sql');
if ($installSql === false || $installSql === '') {
    throw new RuntimeException('Unable to read app/install/install.sql');
}

$statements = (new NotificationRepository())->schemaStatements();
functionalCheck(count($statements) === 3, 'The notification repository grew without install.sql coverage');

foreach ($statements as $statement) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $statement, $tableMatch)) {
        throw new RuntimeException('Unparseable schema statement: ' . $statement);
    }
    $table = $tableMatch[1];
    $createTag = 'CREATE TABLE `' . $table . '`';
    functionalCheck(str_contains($installSql, $createTag),
        $table . ' is missing from install.sql');

    $createStart = strpos($installSql, $createTag);
    $createEnd = (int)strpos($installSql, ';', $createStart);
    $block = substr($installSql, $createStart, $createEnd - $createStart);
    functionalCheck(str_contains($block, 'ENGINE=InnoDB') && str_contains($block, 'utf8mb4'),
        $table . ' in install.sql drifted from the runtime engine/charset');

    $bodyStart = (int)strpos($statement, '(');
    $bodyEnd = (int)strrpos($statement, ')');
    $body = substr($statement, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
    foreach (explode(',', $body) as $part) {
        $part = trim($part);
        if (preg_match('/^`([a-z_]+)`\s+(?:bigint|tinyint|int|varchar|char|text|date)\b/i', $part, $column)) {
            functionalCheck(str_contains($block, '`' . $column[1] . '`'),
                $table . '.' . $column[1] . ' is missing from install.sql');
        }
        if (preg_match('/^(?:UNIQUE )?KEY `([a-z_]+)`/i', $part, $index)) {
            functionalCheck(str_contains($block, '`' . $index[1] . '`'),
                $table . ' index ' . $index[1] . ' is missing from install.sql');
        }
    }
}

echo "Install notification schema tests passed: install.sql defines every notification table, column and index\n";
