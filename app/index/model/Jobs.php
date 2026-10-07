<?php
declare (strict_types=1);

namespace app\index\model;

use app\service\AutomaticSchedule;
use app\service\BilibiliTaskExecutor;
use app\service\TaskExecutionLock;
use think\Collection;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Session;
use think\Model;

class Jobs extends Model
{
    protected $pk = 'id';

    private const EXECUTION_LEASE_SECONDS = 1800;
    private static array $executionLeases = [];

    public static function add($type, $user_id)
    {
        $self = new static();
        $tasks = Tasks::getTaskList($type);
        $nextExecute = self::nextExecutionForAccount((string)$type, (string)$user_id);
        foreach ($tasks as $key => $value) {
            $offline = $type === 'bilibili'
                && BilibiliTaskExecutor::offlineReason((string)$value['execute_name']) !== null;
            $taskNextExecute = $offline ? 0 : $nextExecute;
            if ($offline || ($value['vip'] == 1 && empty(Session::get('user.vip_start')))) {
                $self->insert([
                    'uid' => Session::get('user.uid'),
                    'type' => $type,
                    'user_id' => $user_id,
                    'do' => $value['execute_name'],
                    'state' => 0,
                    'nextExecute' => $taskNextExecute,
                ]);
            } else {
                $self->insert([
                    'uid' => Session::get('user.uid'),
                    'type' => $type,
                    'user_id' => $user_id,
                    'do' => $value['execute_name'],
                    'state' => 1,
                    'nextExecute' => $taskNextExecute,
                ]);
            }
        }
    }

    /**
     * refreshJob 管理员后台编辑任务后更新Jobs任务信息
     * @param $type
     * @param $user_id
     * @return void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public static function refreshJob($type, $user_id, $uid = null)
    {
        $uid = self::resolveTenantUid($uid);
        if ($uid === null) {
            // Job rows must never be created without an owning tenant.
            return false;
        }

        $self = new static();
        $tasks = Tasks::getTaskList($type);
        $nextExecute = self::nextExecutionForAccount((string)$type, (string)$user_id, $uid);
        foreach ($tasks as $key => $value) {
            $taskName = (string)$value['execute_name'];
            $offline = $type === 'bilibili'
                && BilibiliTaskExecutor::offlineReason($taskName) !== null;
            if (!$self::getJobInfo($type, $user_id, $value['execute_name'], $uid)) {
                $self->create([
                    'uid' => $uid,
                    'type' => $type,
                    'user_id' => $user_id,
                    'do' => $value['execute_name'],
                    'state' => $offline ? 0 : 1,
                    'nextExecute' => $offline ? 0 : $nextExecute,
                ]);
            }
        }
        self::disableOfflineBilibiliJobs($type, (string)$user_id, $uid);
        return true;
    }

    /**
     * getJobInfo
     * @param $type
     * @param $user_id
     * @param $do
     * @return Jobs|array|false|mixed|Model
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public static function getJobInfo($type = null, $user_id = null, $do = null, $uid = null)
    {
        $uid = self::resolveTenantUid($uid);
        if ($uid === null) {
            return false;
        }

        $self = new static();
        if ($result = $self->where('type', $type)
            ->where('user_id', $user_id)
            ->where('do', $do)
            ->where('uid', $uid)
            ->find()) {
            return $result;
        }
        return false;
    }

    /**
     * addNeteaseJob
     * @param $data
     * @return false|int|string
     * @author BadCen
     */
    public static function addNeteaseJob($data)
    {
        $self = new static();
        $tasks = Tasks::getTaskList('netease');
        $nextExecute = self::nextExecutionForAccount('netease', (string)$data['user_id']);
        foreach ($tasks as $key => $value) {
            $result = $self->insert([
                'uid' => Session::get('user.uid'),
                'type' => 'netease',
                'user_id' => $data['user_id'],
                'do' => $value['execute_name'],
                'nextExecute' => $nextExecute,
            ]);
        }
        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * addBilibiliJob
     * @param $data
     * @return bool
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function addBilibiliJob($data)
    {
        $self = new static();
        $tasks = Tasks::getTaskList('bilibili');
        $nextExecute = self::nextExecutionForAccount('bilibili', (string)$data['mid']);
        foreach ($tasks as $key => $value) {
            $offline = BilibiliTaskExecutor::offlineReason((string)$value['execute_name']) !== null;
            $result = $self->insert([
                'uid' => Session::get('user.uid'),
                'type' => 'bilibili',
                'user_id' => $data['mid'],
                'do' => $value['execute_name'],
                'state' => $offline ? 0 : 1,
                'nextExecute' => $offline ? 0 : $nextExecute,
            ]);
        }
        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * updateJob 更新账号后更新任务信息
     * @param $type
     * @return bool
     * @author BadCen
     */
    public static function updateJob($type = null, $user_id = null, $uid = null)
    {
        $uid = self::resolveTenantUid($uid);
        if ($uid === null) {
            return false;
        }

        $self = new static();
        $tasks = Tasks::getTaskList($type);
        $nextExecute = self::nextExecutionForAccount((string)$type, (string)$user_id, $uid);
        foreach ($tasks as $key => $value) {
            if ($type === 'bilibili'
                && BilibiliTaskExecutor::offlineReason((string)$value['execute_name']) !== null) {
                $self->where('type', '=', $type)
                    ->where('user_id', '=', $user_id)
                    ->where('uid', '=', $uid)
                    ->where('do', '=', $value['execute_name'])
                    ->update(['state' => 0, 'nextExecute' => 0]);
                continue;
            }
            if ($value['vip'] == 1 && Session::get('user.vip_start')) {
                $self->where('type', '=', $type)
                    ->where('user_id', '=', $user_id)
                    ->where('uid', '=', $uid)
                    ->where('state', '=', -1)
                    ->where('do', '=', $value['execute_name'])
                    ->update(['state' => 1, 'nextExecute' => $nextExecute]);
            } else {
                $self->where('type', '=', $type)
                    ->where('user_id', '=', $user_id)
                    ->where('uid', '=', $uid)
                    ->where('state', '=', -1)
                    ->where('do', '=', $value['execute_name'])
                    ->update(['state' => 1, 'nextExecute' => $nextExecute]);
            }
        }
        self::disableOfflineBilibiliJobs((string)$type, (string)$user_id, $uid);
        return true;
    }

    /**
     * findByUserId
     * @param $user_id
     * @return Jobs|array|false|Model|null
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function findByUserId($type, $user_id)
    {
        $self = new static();
        if ($result = $self->where('user_id', $user_id)->where('type', $type)->where('uid', Session::get('user.uid'))->select()) {
            return $result;
        }
        return false;
    }

    /**
     * switchState
     * @param $user_id
     * @param $do
     * @return Jobs|false
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function switchState($type, $user_id, $do)
    {
        $self = new static();
        $sql = $self->where('type', $type)
            ->where('user_id', $user_id)
            ->where('do', $do)
            ->where('uid', Session::get('user.uid'));
        if ($ret = $sql->find()) {
            $id = (int)$ret['id'];
            if (!TaskExecutionLock::acquire('job:' . $id)) {
                return false;
            }
            try {
                $ret = (new static())->where('id', $id)->find();
                if (!$ret) {
                    return false;
                }
                if ($type === 'bilibili'
                    && BilibiliTaskExecutor::offlineReason((string)$do) !== null) {
                    return $sql->update(['state' => 0, 'nextExecute' => 0]);
                }
                $state = (int)$ret['state'] === 1 ? 0 : 1;
                $updates = ['state' => $state];
                if ($state === 1 && (int)$ret['nextExecute'] <= 0) {
                    $updates['nextExecute'] = self::nextExecutionForAccount((string)$type, (string)$user_id);
                }
                $result = $sql->update($updates);
                return $result;
            } finally {
                TaskExecutionLock::release('job:' . $id);
            }
        }
        return false;
    }

    /**
     * getUnexecutedList
     * @param null $type
     * @return Jobs[]|array|false|Collection
     * @throws DataNotFoundException&
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function getUnexecutedList($type = null, $filter = [])
    {
        $self = new static();
        $query = $self->where($filter)->where([['type', '=', $type], ['zid', '=', 1], ['state', '=', 1], ['nextExecute', '>', 0], ['nextExecute', '<=', time()]]);
        if ($type === 'bilibili') {
            $query->whereIn('do', BilibiliTaskExecutor::executableTasks());
        }
        $result = $query
            ->limit((int)config('sys.interval') ?? 0)
            ->select();
        if ($result) {
            return $result;
        }
        return false;
    }

    /**
     * delJob
     * @param $type
     * @param $data
     * @return bool
     * @author BadCen
     */
    public static function delJob($type, $data, $uid = null)
    {
        $uid = self::resolveTenantUid($uid);
        if ($uid === null) {
            return false;
        }

        $self = new static();
        $result = $self->where('type', $type)
            ->where('user_id', $data)
            ->where('uid', $uid)
            ->delete();
        return $result === false ? false : $result;
    }

    /**
     * jobCount 任务数量
     * @return false|int
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function jobCount()
    {
        $self = new static();
        return $self->count('id');
    }

    /**
     * updateJobInfo 更新任务运行信息
     * @param $user_id
     * @param $type
     * @param array $data
     * @return Jobs|false
     * @author BadCen
     */
    public static function updateJobInfo($type, $do, $user_id, $data = [], $uid = null)
    {
        $uid = self::resolveTenantUid($uid);
        if ($uid === null) {
            return false;
        }

        $self = new static();
        $result = $self->where([
            'type' => $type,
            'do' => $do,
            'user_id' => $user_id,
            'uid' => $uid,
        ])->update($data);
        return $result === false ? false : $result;
    }

    /**
     * Atomically lease a due job before contacting an upstream service.
     *
     * The compare-and-swap on nextExecute prevents schedulers and CLI commands
     * from executing the same selected row concurrently. If a worker exits,
     * the lease expires and the job becomes eligible for a later retry.
     */
    public static function claimDueJob(int $id, int $expectedNextExecute, ?int $now = null): bool
    {
        if ($id <= 0 || $expectedNextExecute <= 0) {
            return false;
        }

        $now = $now ?? time();
        if ($expectedNextExecute > $now) {
            return false;
        }

        if (!TaskExecutionLock::acquire('job:' . $id)) {
            return false;
        }

        try {
            $affected = (new static())
                ->where('id', '=', $id)
                ->where('zid', '=', 1)
                ->where('state', '=', 1)
                ->where('nextExecute', '=', $expectedNextExecute)
                ->where('nextExecute', '>', 0)
                ->where('nextExecute', '<=', $now)
                ->update(['nextExecute' => $now + self::EXECUTION_LEASE_SECONDS]);
            if ((int)$affected === 1) {
                self::$executionLeases[$id] = $now + self::EXECUTION_LEASE_SECONDS;
                return true;
            }
        } catch (\Throwable $exception) {
            TaskExecutionLock::release('job:' . $id);
            throw $exception;
        }
        TaskExecutionLock::release('job:' . $id);
        return false;
    }

    /** Finish only the lease we own; never overwrite a later user schedule or cancellation. */
    public static function updateClaimedJob(int $id, array $updates): bool
    {
        if (!isset(self::$executionLeases[$id])) {
            return false;
        }
        return (int)(new static())->where('id', $id)->where('state', 1)
            ->where('nextExecute', self::$executionLeases[$id])->update($updates) > 0;
    }

    public static function releaseDueJob(int $id): void
    {
        unset(self::$executionLeases[$id]);
        TaskExecutionLock::release('job:' . $id);
    }

    /** Queue manual work only while idle; the worker holds this lock until its finally block. */
    public static function requestImmediate(array $ids): int
    {
        $updated = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0 || !TaskExecutionLock::acquire('job:' . $id)) {
                continue;
            }
            try {
                $updated += (int)(new static())->where('id', $id)->where('state', 1)
                    ->update(['nextExecute' => time()]);
            } finally {
                TaskExecutionLock::release('job:' . $id);
            }
        }
        return $updated;
    }

    public static function pauseVipJobs(string $type, int $uid, string $userId): void
    {
        $tasks = $type === 'epic' ? ['weeklyGameNotify']
            : Tasks::where('type', $type)->where('vip', 1)->column('execute_name');
        if ($tasks !== []) {
            (new static())->where('type', $type)->where('uid', $uid)->where('user_id', $userId)
                ->whereIn('do', $tasks)->update(['state' => 0, 'nextExecute' => 0]);
        }
    }

    /** Best-effort housekeeping must not abort an otherwise runnable scheduler batch. */
    public static function retireOfflineJobs(): void
    {
        $key = 'maintenance:retired-jobs';
        try {
            if (!TaskExecutionLock::acquire($key)) {
                return;
            }
        } catch (\Throwable $exception) {
            error_log('LoopDeck: retired-task maintenance lock unavailable');
            return;
        }
        try {
            $ids = (new static())->where(static function ($query): void {
                $query->where(static function ($bilibili): void {
                    $bilibili->where('type', 'bilibili')->whereIn('do', array_keys(BilibiliTaskExecutor::OFFLINE_TASKS));
                })->whereOr('type', 'heybox');
            })
                ->where(static function ($query): void {
                    $query->where('state', '<>', 0)->whereOr('nextExecute', '<>', 0);
                })->order('id')->limit(500)->column('id');
            foreach ($ids as $id) {
                // PK updates avoid scanning and locking the mutable due-time index.
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    try {
                        (new static())->where('id', (int)$id)
                            ->update(['state' => 0, 'nextExecute' => 0]);
                        break;
                    } catch (\Throwable $exception) {
                        if ($attempt === 2) {
                            throw $exception;
                        }
                        usleep(20000);
                    }
                }
            }
        } catch (\Throwable $exception) {
            error_log('LoopDeck: retired-task maintenance deferred to the next batch');
        } finally {
            TaskExecutionLock::release($key);
        }
    }

    private static function nextExecutionForAccount(string $type, string $userId, $uid = null): int
    {
        $uid = self::resolveTenantUid($uid);
        if ($type === '' || $userId === '' || $uid === null) {
            return 0;
        }

        $timing = Accounts::where('type', $type)
            ->where('user_id', $userId)
            ->where('uid', $uid)
            ->value('timing');

        return AutomaticSchedule::nextExecution(
            $type,
            $userId,
            is_string($timing) ? $timing : null
        ) ?? 0;
    }

    private static function disableOfflineBilibiliJobs(string $type, string $userId, int $uid): void
    {
        if ($type !== 'bilibili' || $userId === '' || $uid <= 0) {
            return;
        }

        $offlineTasks = array_keys(BilibiliTaskExecutor::OFFLINE_TASKS);
        if ($offlineTasks === []) {
            return;
        }

        (new static())
            ->where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', $uid)
            ->whereIn('do', $offlineTasks)
            ->update(['state' => 0, 'nextExecute' => 0]);
    }

    /**
     * Resolve the tenant owner for a job operation. Web requests normally use
     * the logged-in session; scheduler/CLI callers must pass the row's uid
     * explicitly. Invalid or missing IDs fail closed instead of issuing an
     * unscoped query.
     */
    private static function resolveTenantUid($uid = null): ?int
    {
        if ($uid === null) {
            try {
                $uid = Session::get('user.uid');
            } catch (\Throwable $exception) {
                return null;
            }
        }

        $resolved = filter_var($uid, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return $resolved === false ? null : (int)$resolved;
    }

}
