<?php

namespace app\install\controller;

use app\install\validate\Install;
use mysqli;
use mysqli_sql_exception;
use think\exception\ValidateException;
use think\facade\Request;
use think\facade\View;

class Index
{
    public function __construct()
    {
        if (is_file(root_path() . 'config' . DIRECTORY_SEPARATOR . 'Db.php')) {
            exit('程序已经安装。如需重新安装，请先备份并移除 config/Db.php。');
        }
    }

    public function checkfun(): bool
    {
        return version_compare(PHP_VERSION, '8.0.0', '>=')
            && extension_loaded('mysqli')
            && extension_loaded('curl')
            && function_exists('file_get_contents');
    }

    public function index()
    {
        return View::fetch($this->checkfun() ? 'install' : 'status');
    }

    public function install()
    {
        if (!Request::isAjax()) {
            return resultJson(-1, '非法请求');
        }

        $input = Request::post();
        try {
            validate(Install::class)->scene('install')->check($input);
        } catch (ValidateException $exception) {
            return resultJson(-1, $exception->getMessage());
        }

        $database = [
            'hostname' => trim((string)$input['install-db-hostname']),
            'hostport' => (int)$input['install-db-hostport'],
            'database' => trim((string)$input['install-db-database']),
            'username' => trim((string)$input['install-db-username']),
            'password' => (string)$input['install-db-password'],
        ];

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $connection = new mysqli(
                $database['hostname'],
                $database['username'],
                $database['password'],
                $database['database'],
                $database['hostport']
            );
            $connection->set_charset('utf8mb4');
            $this->importSchema($connection);
            $this->createRuntimeConfig($connection);
            $this->createSite($connection);
            $this->createAdministrator($connection, $input);
            $this->writeDatabaseConfig($database);
            $connection->close();
        } catch (mysqli_sql_exception $exception) {
            return resultJson(-1, '数据库安装失败：' . $exception->getMessage());
        } catch (\Throwable $exception) {
            return resultJson(-1, '安装失败：' . $exception->getMessage());
        }

        return resultJson(0, '安装成功');
    }

    private function importSchema(mysqli $connection): void
    {
        $schema = root_path() . 'app' . DIRECTORY_SEPARATOR . 'install'
            . DIRECTORY_SEPARATOR . 'install.sql';
        if (!is_file($schema)) {
            throw new \RuntimeException('缺少数据库结构文件：' . $schema);
        }

        $sql = (string)file_get_contents($schema);
        $sql = preg_replace('/\);\s*\((?=3[2-4],)/', '),(', $sql) ?? $sql;
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: []),
            static fn(string $statement): bool => $statement !== ''
        );
        foreach ($statements as $statement) {
            $connection->query($statement);
        }
    }

    private function createSite(mysqli $connection): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
        if (!preg_match('/^[a-z0-9.:\-\[\]]+$/i', $host)) {
            $host = '127.0.0.1:8000';
        }

        $statement = $connection->prepare(
            'INSERT INTO `cloud_weblist` '
            . '(`web_id`,`user_qq`,`mail`,`webname`,`title`,`domain`,`start_time`,'
            . '`end_time`,`prefix`,`web_key`,`status`) '
            . 'VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $qq = '10000';
        $mail = 'admin@localhost';
        $webname = 'OneTool';
        $title = '你的私人助手';
        $start = date('Y-m-d');
        $end = '2099-12-31';
        $prefix = 'cloud_';
        $key = getRandStr(32);
        $statement->bind_param(
            'sssssssss',
            $qq,
            $mail,
            $webname,
            $title,
            $host,
            $start,
            $end,
            $prefix,
            $key
        );
        $statement->execute();
    }

    private function createRuntimeConfig(mysqli $connection): void
    {
        $statement = $connection->prepare(
            'INSERT INTO `cloud_configs` (`k`, `v`) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)'
        );
        $key = 'cronkey';
        $value = bin2hex(random_bytes(24));
        $statement->bind_param('ss', $key, $value);
        $statement->execute();
    }

    private function createAdministrator(mysqli $connection, array $input): void
    {
        $statement = $connection->prepare(
            'INSERT INTO `cloud_users` '
            . '(`uid`,`web_id`,`username`,`password`,`nickname`,`mail`,`qq`,`power`,'
            . '`login_ip`,`login_time`,`state`,`quota`,`sid`) '
            . 'VALUES (1, 1, ?, ?, ?, ?, ?, 6, ?, ?, 1, 100, ?)'
        );
        $username = trim((string)$input['install-admin-username']);
        $password = md5((string)$input['install-admin-password']);
        $qq = preg_replace('/\D+/', '', (string)$input['install-admin-qq']);
        $nickname = get_qqname($qq);
        $mail = $qq . '@qq.com';
        $ip = real_ip();
        $time = time();
        $sid = md5($username . $password . $time . getRandStr(8));
        $statement->bind_param(
            'ssssssis',
            $username,
            $password,
            $nickname,
            $mail,
            $qq,
            $ip,
            $time,
            $sid
        );
        $statement->execute();
    }

    private function writeDatabaseConfig(array $database): void
    {
        $path = root_path() . 'config' . DIRECTORY_SEPARATOR . 'Db.php';
        $temporary = $path . '.tmp';
        $contents = "<?php\n\nreturn " . var_export($database, true) . ";\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) === false
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('无法写入 config/Db.php');
        }
    }
}
