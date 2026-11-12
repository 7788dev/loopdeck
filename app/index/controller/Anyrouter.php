<?php

declare(strict_types=1);

namespace app\index\controller;

use anyrouter\Client;
use anyrouter\ProxyEndpoint;
use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\service\AnyRouterTaskExecutor;
use app\service\AutomaticSchedule;
use app\service\TaskExecutionLock;
use think\facade\Db;
use think\facade\Request;
use think\facade\Session;
use Throwable;

class Anyrouter
{
    protected $middleware = [\app\middleware\CheckLoginUser::class, \app\middleware\CheckAjaxRequest::class];

    public function handle($act = null)
    {
        if (!Request::isPost()) {
            return response('Method Not Allowed', 405);
        }
        if ($act === 'save') {
            return $this->save();
        }
        $userId = trim((string)Request::post('user_id', ''));
        $account = Accounts::findByUserId('anyrouter', $userId);
        if (!$account) {
            return resultJson(0, '账号不存在或无权操作');
        }
        if ($act === 'logs') {
            return json(TaskLogs::searchLogs('anyrouter', $userId) ?: []);
        }
        if ($act === 'delete') {
            try {
                $deleted = \app\admin\model\Accounts::delByid((int)$account['id'], true, (int)Session::get('user.uid'));
                return resultJson($deleted ? 1 : 0, $deleted ? '删除成功' : '删除失败');
            } catch (Throwable $exception) {
                return resultJson(0, '删除失败，请稍后重试');
            }
        }
        if (!in_array($act, ['timing', 'toggle', 'retry'], true)) {
            return resultJson(0, '不支持的操作');
        }
        Jobs::refreshJob('anyrouter', $userId);
        $job = Jobs::getJobInfo('anyrouter', $userId, 'sign_in');
        if (!$job) {
            return resultJson(0, '任务不存在或已停用');
        }
        return $this->withJobLock((int)$job['id'], function () use ($act, $userId, $job) {
            // Reload under the same lock used by the scheduler claim.
            $account = Accounts::findByUserId('anyrouter', $userId);
            $job = Jobs::where('id', $job['id'])->find();
            if (!$account || !$job) {
                return resultJson(0, '账号或任务已删除');
            }
            if ($act === 'timing') {
                $timing = trim((string)Request::post('timing', ''));
                if ($timing !== '' && !AutomaticSchedule::isConfigured($timing)) {
                    return resultJson(0, '请输入有效时间，格式为 HH:MM');
                }
                $next = AutomaticSchedule::nextExecution('anyrouter', $userId, $timing) ?? 0;
                Db::transaction(static function () use ($account, $job, $timing, $next): void {
                    $account->save(['timing' => $timing !== '' ? $timing : null]);
                    $job->save(['nextExecute' => $next]);
                });
                return resultJson(1, $next > 0 ? '签到时间已保存' : '已关闭自动签到');
            }
            $task = Tasks::where('type', 'anyrouter')->where('execute_name', 'sign_in')->find();
            $enabling = $act === 'retry' || (int)$job['state'] !== 1;
            if ($enabling && (!$task || (int)$task['state'] !== 1)) {
                return resultJson(0, '管理员已停用此任务');
            }
            $user = Users::where('uid', Session::get('user.uid'))->where('web_id', WEB_ID)->find();
            if ($enabling && (!$user || ((int)$task['vip'] === 1 && (int)strtotime((string)$user['vip_end']) <= time()))) {
                return resultJson(0, '此功能需要有效的 VIP 权益');
            }
            if ($enabling && (int)$account['state'] !== 1) {
                return resultJson(0, '请先更新账号 Cookie');
            }
            if ($act === 'toggle') {
                $job->save(['state' => $enabling ? 1 : 0, 'nextExecute' => $enabling
                    ? (AutomaticSchedule::nextExecution('anyrouter', $userId, (string)$account['timing']) ?? 0) : 0]);
                return resultJson(1, $enabling ? '已启用签到' : '已暂停签到');
            }
            if (!AutomaticSchedule::isConfigured((string)$account['timing'])) {
                return resultJson(0, '请先设置签到时间');
            }
            if ((int)$job['state'] !== 1) {
                return resultJson(0, '请先启用签到任务');
            }
            if ((int)$account['cooling'] > time()) {
                return resultJson(0, '请求过于频繁，请稍后重试');
            }
            Db::transaction(static function () use ($account, $job): void {
                $account->save(['cooling' => time() + 300]);
                $job->save(['nextExecute' => time()]);
            });
            return resultJson(1, '已加入签到队列，请稍后查看运行日志');
        });
    }

    private function save()
    {
        try {
            $id = Client::normalizeUser((string)Request::post('api_user', ''));
            $existing = Accounts::findByUserId('anyrouter', $id);
            $previous = $existing ? safe_unserialize_array((string)$existing['data']) : [];
            $cookie = trim((string)Request::post('cookie', ''));
            $cookies = $cookie === '' && isset($previous['cookies']) ? $previous['cookies'] : Client::parseCookies($cookie);
            $mode = (string)Request::post('proxy_mode', 'platform');
            if (!in_array($mode, ['platform', 'custom'], true)) {
                return resultJson(0, '请选择平台代理或提供代理');
            }
            $proxy = trim((string)Request::post('proxy_url', ''));
            if ($mode === 'custom' && $proxy === '' && ($previous['proxy_mode'] ?? '') === 'custom') {
                $proxy = (string)($previous['proxy_url'] ?? '');
            }
            $payload = ['api_user' => $id, 'cookies' => $cookies, 'proxy_mode' => $mode,
                'proxy_url' => $mode === 'custom' ? ProxyEndpoint::normalize($proxy) : ''];
            $name = trim((string)Request::post('nickname', (string)($previous['remark'] ?? $previous['nickname'] ?? '')));
            if (mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                return resultJson(0, '账号备注不能超过 80 个字符');
            }
        } catch (\InvalidArgumentException $exception) {
            return resultJson(0, $exception->getMessage());
        }
        $lock = 'anyrouter-save:' . (int)Session::get('user.uid');
        try {
            if (!TaskExecutionLock::acquire($lock)) {
                return resultJson(0, '正在验证账号，请稍后重试');
            }
        } catch (Throwable $exception) {
            return resultJson(0, '账号验证暂不可用，请稍后重试');
        }
        try {
            $auth = $this->executor()->validateAccount($payload);
            if (!$auth['success']) {
                return resultJson(0, (string)($auth['reason'] ?? '账号验证失败'));
            }
            $payload['nickname'] = $name !== '' ? $name : $auth['profile']['nickname'];
            $payload['remark'] = $name;
            $payload['profile'] = $auth['profile'];
            $save = static function () use ($id, $payload) {
                $result = Accounts::add('anyrouter', $id, $payload);
                return (int)($result->getData()['code'] ?? 0) === 1
                    ? resultJson(1, '账号已保存', ['url' => '/index/console/anyrouter/info/' . $id]) : $result;
            };
            $job = $existing ? Jobs::getJobInfo('anyrouter', $id, 'sign_in') : null;
            return $job ? $this->withJobLock((int)$job['id'], $save) : $save();
        } catch (Throwable $exception) {
            return resultJson(0, '账号验证或保存失败，请稍后重试');
        } finally {
            TaskExecutionLock::release($lock);
        }
    }

    private function withJobLock(int $jobId, \Closure $callback)
    {
        $key = 'job:' . $jobId;
        if (!TaskExecutionLock::acquire($key)) {
            return resultJson(0, '任务正在执行，请稍后重试');
        }
        try {
            return $callback();
        } finally {
            TaskExecutionLock::release($key);
        }
    }

    protected function executor(): AnyRouterTaskExecutor
    {
        return new AnyRouterTaskExecutor();
    }
}
