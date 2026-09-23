<?php

declare(strict_types=1);

namespace app\service;

use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Users;
use Closure;
use think\facade\Db;

final class CheckinAccounts
{
    public function __construct(private ?Closure $factory = null)
    {
    }

    public function authenticate(string $type, array $input): array
    {
        return $this->adapter($type)->authenticate($input);
    }

    public function store(string $type, array $identity, int $uid, string $expectedId = ''): array
    {
        PlatformRegistry::get($type);
        $userId = (string)$identity['user_id'];
        if ($expectedId !== '' && !hash_equals($expectedId, $userId)) {
            throw new PlatformException('当前凭据属于其他账号，请从添加账号入口添加', false, 0);
        }
        $result = AccountMutex::run($type, $userId, static function () use ($type, $identity, $uid, $userId): array {
            return Db::transaction(static function () use ($type, $identity, $uid, $userId): array {
                $user = Users::where('uid', $uid)->where('web_id', 1)->where('state', 1)->lock(true)->find();
                if (!$user) {
                    throw new PlatformException('用户状态无效，请重新登录', false, 0);
                }
                if (Accounts::where('type', $type)->where('user_id', $userId)->where('uid', '<>', $uid)->find()) {
                    throw new PlatformException('该平台账号已被其他用户绑定', false, 0);
                }
                $existing = Accounts::where('uid', $uid)->where('type', $type)->where('user_id', $userId)->find();
                if (!$existing && Accounts::where('uid', $uid)->count('id') >= (int)$user['quota']) {
                    throw new PlatformException('账号配额不足，请先购买挂机配额', false, 0);
                }
                $data = serialize($identity + ['profile_updated_at' => time()]);
                if ($existing) {
                    Accounts::where('id', (int)$existing['id'])->update(['data' => $data, 'state' => 1]);
                    Jobs::where('uid', $uid)->where('type', $type)->where('user_id', $userId)->where('state', -1)
                        ->update(['state' => 1, 'nextExecute' => AutomaticSchedule::nextExecution(
                            $type, $userId, (string)($existing['timing'] ?? '')) ?? 0]);
                } else {
                    Accounts::insert(['uid' => $uid, 'zid' => 1, 'type' => $type, 'user_id' => $userId,
                        'data' => $data, 'state' => 1, 'addtime' => date('Y-m-d H:i:s')]);
                }
                Jobs::refreshJob($type, $userId, $uid);
                return ['user_id' => $userId, 'updated' => (bool)$existing, 'data' => $data];
            });
        });
        (new AccountSnapshot())->prime(['uid' => $uid, 'type' => $type, 'user_id' => $userId, 'data' => $result['data']],
            (array)($identity['profile'] ?? []));
        unset($result['data']);
        return $result;
    }

    /** Reload under the account mutex; never refresh a token from a stale page row. */
    public function withAccount(array $account, callable $operation): mixed
    {
        return AccountMutex::run((string)$account['type'], (string)$account['user_id'], function () use ($account, $operation): mixed {
            $fresh = Accounts::where('id', (int)$account['id'])->where('uid', (int)$account['uid'])
                ->where('zid', 1)->where('state', 1)->find();
            if (!$fresh) {
                throw new PlatformException('账号已删除或停用，请更新账号', true, 0);
            }
            $payload = safe_unserialize_array((string)$fresh['data']);
            $serialized = (string)$fresh['data'];
            $persist = static function (array $credentials) use (&$payload, &$serialized, $fresh): void {
                $payload['credentials'] = $credentials;
                $next = serialize($payload);
                if ($next === $serialized) {
                    return;
                }
                $changed = Accounts::where('id', (int)$fresh['id'])->where('uid', (int)$fresh['uid'])
                    ->where('data', $serialized)->update(['data' => $next]);
                if ((int)$changed !== 1) {
                    throw new PlatformException('账号已更新，请稍后重试', false, 60);
                }
                $serialized = $next;
            };
            return $operation($this->adapter((string)$fresh['type'], $persist), $payload);
        });
    }

    public function profile(array $account): array
    {
        return $this->withAccount($account, static fn(CheckinAdapter $adapter, array $payload): array => $adapter->profile($payload));
    }

    /** Remove the account with its jobs, logs and cached state. */
    public function delete(array $account): void
    {
        $type = (string)$account['type'];
        $userId = (string)$account['user_id'];
        $uid = (int)$account['uid'];
        AccountMutex::run($type, $userId, static function () use ($account, $type, $userId, $uid): void {
            Db::transaction(static function () use ($account, $type, $userId, $uid): void {
                Jobs::where('uid', $uid)->where('type', $type)->where('user_id', $userId)->delete();
                Accounts::where('id', (int)$account['id'])->where('uid', $uid)->delete();
                TaskLogs::where('type', $type)->where('user_id', $userId)->delete();
            });
        });
        CheckinTaskExecutor::forget($account);
        (new AccountSnapshot())->forget($account);
    }

    /** Display fields only: credentials never leave the payload. */
    public static function display(array $account): array
    {
        $payload = safe_unserialize_array((string)($account['data'] ?? ''));
        $avatar = (string)($payload['avatar'] ?? '');
        return [
            'user_id' => (string)$account['user_id'],
            'nickname' => trim((string)($payload['nickname'] ?? '')) ?: PlatformRegistry::get((string)$account['type'])['name'] . '用户',
            'avatar' => str_starts_with($avatar, 'https://') ? $avatar : '',
            'state' => (int)($account['state'] ?? 0),
            'timing' => (string)($account['timing'] ?? ''),
            'addtime' => (string)($account['addtime'] ?? ''),
        ];
    }

    private function adapter(string $type, ?Closure $persist = null): CheckinAdapter
    {
        return $this->factory !== null ? ($this->factory)($type, $persist) : PlatformRegistry::adapter($type, null, $persist);
    }
}
