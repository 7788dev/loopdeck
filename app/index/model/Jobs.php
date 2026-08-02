<?php
declare (strict_types=1);

namespace app\index\model;

use app\service\AutomaticSchedule;
use app\service\BilibiliTaskExecutor;
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

    public static function add($type, $user_id)
    {
        $self = new static();
        $tasks = Tasks::getTaskList($type);
        $nextExecute = self::nextExecutionForAccount((string)$type, (string)$user_id);
        foreach ($tasks as $key => $value) {
            $offline = $type === 'bilibili'
                && BilibiliTaskExecutor::offlineReason((string)$value['execute_name']) !== null;
            if ($offline || ($value['vip'] == 1 && empty(Session::get('user.vip_start')))) {
                $self->insert([
                    'uid' => Session::get('user.uid'),
                    'type' => $type,
                    'user_id' => $user_id,
                    'do' => $value['execute_name'],
                    'state' => 0,
                    'nextExecute' => $nextExecute,
                ]);
            } else {
                $self->insert([
                    'uid' => Session::get('user.uid'),
                    'type' => $type,
                    'user_id' => $user_id,
                    'do' => $value['execute_name'],
                    'state' => 1,
                    'nextExecute' => $nextExecute,
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
    public static function refreshJob($type, $user_id)
    {
        $self = new static();
        $tasks = Tasks::getTaskList($type);
        $nextExecute = self::nextExecutionForAccount((string)$type, (string)$user_id);
        foreach ($tasks as $key => $value) {
            if (!$self::getJobInfo($type, $user_id, $value['execute_name'])) {
                $self->create([
                    'uid' => Session::get('user.uid'),
                    'type' => $type,
                    'user_id' => $user_id,
                    'do' => $value['execute_name'],
                    'state' => $type === 'bilibili'
                        && BilibiliTaskExecutor::offlineReason((string)$value['execute_name']) !== null
                        ? 0
                        : 1,
                    'nextExecute' => $nextExecute,
                ]);
            }
        }
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
    public static function getJobInfo($type = null, $user_id = null, $do = null)
    {
        $self = new static();
        if ($result = $self->where('type', $type)->where('user_id', $user_id)->where('do', $do)->find()) {
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
     * addSportJob
     * @param $data
     * @return bool
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function addSportJob($data)
    {
        $self = new static();
        $tasks = Tasks::getTaskList('sport');
        $nextExecute = self::nextExecutionForAccount('sport', (string)$data['user_id']);
        foreach ($tasks as $key => $value) {
            $result = $self->insert([
                'uid' => Session::get('user.uid'),
                'type' => 'sport',
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
     * updateJob 更新账号后更新任务信息
     * @param $type
     * @return bool
     * @author BadCen
     */
    public static function updateJob($type = null, $user_id = null)
    {
        $self = new static();
        $tasks = Tasks::getTaskList($type);
        $nextExecute = self::nextExecutionForAccount((string)$type, (string)$user_id);
        foreach ($tasks as $key => $value) {
            if ($type === 'bilibili'
                && BilibiliTaskExecutor::offlineReason((string)$value['execute_name']) !== null) {
                $self->where('type', '=', $type)
                    ->where('user_id', '=', $user_id)
                    ->where('do', '=', $value['execute_name'])
                    ->update(['state' => 0]);
                continue;
            }
            if ($value['vip'] == 1 && Session::get('user.vip_start')) {
                $self->where('type', '=', $type)
                    ->where('user_id', '=', $user_id)
                    ->where('state', '=', -1)
                    ->where('do', '=', $value['execute_name'])
                    ->update(['state' => 1, 'nextExecute' => $nextExecute]);
            } else {
                $self->where('type', '=', $type)
                    ->where('user_id', '=', $user_id)
                    ->where('state', '=', -1)
                    ->where('do', '=', $value['execute_name'])
                    ->update(['state' => 1, 'nextExecute' => $nextExecute]);
            }
        }
        return false;
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
            if ($ret->state == -1) {
                $result = $sql->update(['state' => 1]);
            } else {
                $result = $sql->update([
                    'state' => $ret['state'] ^ 1
                ]);
            }
            return $result;
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
        $result = $self->where($filter)->where([['type', '=', $type], ['state', '=', 1], ['nextExecute', '>', 0], ['nextExecute', '<=', time()]])
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
    public static function delJob($type, $data)
    {
        $self = new static();
        if ($result = $self->where('type', $type)->where('user_id', $data)->delete()) {
            return $result;
        }
        return false;
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
    public static function updateJobInfo($type, $do, $user_id, $data = [])
    {
        $self = new static();
        if ($result = $self->where(['type' => $type, 'do' => $do, 'user_id' => $user_id])->update($data)) {
            return $result;
        }
        return false;
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

        $affected = (new static())
            ->where('id', '=', $id)
            ->where('state', '=', 1)
            ->where('nextExecute', '=', $expectedNextExecute)
            ->where('nextExecute', '>', 0)
            ->where('nextExecute', '<=', $now)
            ->update(['nextExecute' => $now + self::EXECUTION_LEASE_SECONDS]);

        return (int)$affected === 1;
    }

    private static function nextExecutionForAccount(string $type, string $userId): int
    {
        $uid = (int)Session::get('user.uid');
        if ($type === '' || $userId === '' || $uid <= 0) {
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

}
