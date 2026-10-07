<?php

declare(strict_types=1);

namespace app\service;

/** Shared by HTTP applications and CLI workers on the same installation. */
final class TaskExecutionLock
{
    private static array $handles = [];

    public static function acquire(string $key): bool
    {
        // Multi-app changes runtime_path() to runtime/cron or runtime/index.
        // Always use the common installation directory for cross-entry locks.
        $directory = (string)config('app.task_lock_directory', root_path() . 'runtime/task-locks');
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.lock';
        if (isset(self::$handles[$key])) {
            return false;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建任务锁目录');
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('无法创建任务执行锁');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        self::$handles[$key] = $handle;
        return true;
    }

    public static function release(string $key): void
    {
        if (isset(self::$handles[$key])) {
            flock(self::$handles[$key], LOCK_UN);
            fclose(self::$handles[$key]);
            unset(self::$handles[$key]);
        }
        // Never unlink a lock file: waiters must continue to use the same inode.
    }
}
