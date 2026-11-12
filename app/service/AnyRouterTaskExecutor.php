<?php

declare(strict_types=1);

namespace app\service;

use anyrouter\Client;
use Closure;
use Throwable;

final class AnyRouterTaskExecutor
{
    private Closure $connector;

    public function __construct(?Closure $connector = null)
    {
        $this->connector = $connector ?? static fn(array $account): Client => (new AnyRouterProxyPool())->connect($account);
    }

    public function validateAccount(array $account): array
    {
        try {
            return ($this->connector)($account)->authenticate((array)($account['cookies'] ?? []), (string)($account['api_user'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'reason' => $exception->getMessage()];
        } catch (\RuntimeException $exception) {
            return ['success' => false, 'reason' => $this->connectionReason($exception)];
        }
    }

    public function execute(string $task, array $account, array $config = []): array
    {
        if ($task !== 'sign_in') {
            return $this->result(['success' => false, 'reason' => '不支持的 AnyRouter 任务'], $config);
        }
        try {
            $client = ($this->connector)($account);
            $auth = $client->authenticate((array)($account['cookies'] ?? []), (string)($account['api_user'] ?? ''));
            $outcome = $auth['success'] ? $client->signIn() : $auth;
        } catch (\InvalidArgumentException $exception) {
            $outcome = ['success' => false, 'reason' => $exception->getMessage()];
        } catch (Throwable $exception) {
            $outcome = ['success' => false, 'retryable' => true, 'reason' => $this->connectionReason($exception)];
        }
        return $this->result($outcome, $config);
    }

    private function result(array $outcome, array $config): array
    {
        $success = !empty($outcome['success']);
        $invalid = !empty($outcome['account_invalid']);
        $retry = !$success && !$invalid && !empty($outcome['retryable']);
        $data = SchedulerRetry::settledData($config);
        $exhausted = false;
        if ($retry) {
            $retryData = SchedulerRetry::dataAfterFailure($config);
            $exhausted = $retryData === null;
            $retry = !$exhausted;
            $data = $retryData ?? $data;
        }
        $message = TaskMessage::compose([['label' => '签到', 'status' => $success
            ? (!empty($outcome['already']) ? TaskMessage::ALREADY : TaskMessage::DONE) : TaskMessage::FAILED,
            'retry' => $retry]]);
        if (!$success) {
            $message = TaskMessage::join([$message, (string)($outcome['reason'] ?? '网站未返回有效结果'),
                $exhausted ? SchedulerRetry::finalMessage(SchedulerRetry::attemptsIn($config) + 1) : '']);
        }
        return ['success' => $success, 'account_invalid' => $invalid, 'message' => $message,
            'retry_after_seconds' => $retry ? 300 : 0, 'retry_exhausted' => $exhausted, 'job_data' => $data];
    }

    public static function jobUpdates(array $result, string $userId, string $timing, ?int $now = null): array
    {
        $now ??= time();
        $next = AutomaticSchedule::nextExecution('anyrouter', $userId, $timing, $now) ?? 0;
        if ($next > 0 && !empty($result['retry_after_seconds'])) {
            $next = $now + 300;
        }
        $updates = ['lastExecute' => date('Y-m-d H:i:s', $now), 'nextExecute' => $next,
            'data' => serialize($result['job_data'] ?? [])];
        if (!empty($result['retry_exhausted'])) {
            $updates['state'] = 0;
            $updates['nextExecute'] = 0;
        }
        return $updates;
    }

    private function connectionReason(Throwable $exception): string
    {
        // Do not forward HTTP client exceptions: they can contain proxy credentials or cookies.
        if ($exception::class === \RuntimeException::class
            && ($exception->getMessage() === '代理地址暂时无法解析，请稍后重试'
                || preg_match('/\A(?:平台代理|提供的代理|暂未找到可用的平台代理)/u', $exception->getMessage()))) {
            return $exception->getMessage();
        }
        return '代理连接失败，请稍后重试';
    }
}
