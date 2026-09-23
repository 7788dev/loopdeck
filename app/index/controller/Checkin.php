<?php

declare(strict_types=1);

namespace app\index\controller;

use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\service\AutomaticSchedule;
use app\service\CheckinAccounts;
use app\service\PlatformException;
use app\service\PlatformRegistry;
use app\service\PlatformVerification;
use think\facade\Request;
use think\facade\Session;
use Throwable;

/** AJAX actions shared by the daily check-in platforms. */
final class Checkin
{
    private const CHALLENGE_KEY = 'checkin_challenge';
    private const CHALLENGE_TTL = 300;

    protected $middleware = [\app\middleware\CheckLoginUser::class, \app\middleware\CheckAjaxRequest::class];

    public function tieba($act = null)
    {
        return $this->handle('tieba', (string)$act);
    }

    public function quark($act = null)
    {
        return $this->handle('quark', (string)$act);
    }

    public function tianyi($act = null)
    {
        return $this->handle('tianyi', (string)$act);
    }

    public function aliyundrive($act = null)
    {
        return $this->handle('aliyundrive', (string)$act);
    }

    private function handle(string $type, string $act)
    {
        if (!Request::isPost()) {
            return resultJson(0, '请使用 POST 请求');
        }
        return match ($act) {
            'add' => $this->add($type),
            'delete' => $this->delete($type),
            'set' => $this->set($type),
            'logs' => $this->logs($type),
            'reExecute' => $this->reExecute($type),
            default => resultJson(0, '不支持的操作'),
        };
    }

    private function add(string $type)
    {
        $input = [];
        foreach (PlatformRegistry::get($type)['fields'] as $field) {
            $value = Request::post($field['name'], '');
            if (!is_string($value) || strlen($value) > (int)$field['max']) {
                return resultJson(0, $field['label'] . '格式不正确');
            }
            $input[$field['name']] = $value;
        }
        $expected = trim((string)Request::post('expected_user_id', ''));
        if ($expected !== '' && !$this->ownedAccount($type, $expected)) {
            return resultJson(0, '要更新的账号不存在或无权操作');
        }
        $challenge = $this->challenge($type);
        if ($challenge !== null) {
            $code = trim((string)Request::post('captcha_code', ''));
            if (preg_match('/\A[A-Za-z0-9]{3,8}\z/', $code) !== 1) {
                return resultJson(0, '请输入图片中的验证码');
            }
            $input += ['login_context' => $challenge, 'captcha_code' => $code];
        }
        $accounts = new CheckinAccounts();
        try {
            $identity = $accounts->authenticate($type, $input);
            $stored = $accounts->store($type, $identity, (int)Session::get('user.uid'), $expected);
        } catch (PlatformVerification $verification) {
            // Only the login context stays on the server; the password does not.
            $token = bin2hex(random_bytes(16));
            Session::set(self::CHALLENGE_KEY, ['type' => $type, 'token' => $token,
                'context' => $verification->context, 'expires_at' => time() + self::CHALLENGE_TTL]);
            return resultJson(2, $verification->getMessage(), ['challenge' => $token, 'captcha' => $verification->image]);
        } catch (PlatformException $exception) {
            Session::delete(self::CHALLENGE_KEY);
            return resultJson(0, $exception->getMessage());
        } catch (Throwable $exception) {
            Session::delete(self::CHALLENGE_KEY);
            return resultJson(0, '保存账号失败，请稍后重试');
        }
        Session::delete(self::CHALLENGE_KEY);
        return resultJson(1, $stored['updated'] ? '账号已更新' : '账号添加成功', [
            'url' => '/index/console/' . $type . '/info/' . $stored['user_id'],
        ]);
    }

    /** The pending captcha login for this platform, if the browser sent its token. */
    private function challenge(string $type): ?array
    {
        $token = trim((string)Request::post('challenge', ''));
        $entry = Session::get(self::CHALLENGE_KEY);
        if ($token === '' || !is_array($entry) || ($entry['type'] ?? '') !== $type
            || !is_string($entry['token'] ?? null) || !hash_equals($entry['token'], $token)
            || (int)($entry['expires_at'] ?? 0) < time() || !is_array($entry['context'] ?? null)) {
            Session::delete(self::CHALLENGE_KEY);
            return null;
        }
        return $entry['context'];
    }

    private function delete(string $type)
    {
        $account = $this->ownedAccount($type, trim((string)Request::post('user_id', '')));
        if (!$account) {
            return resultJson(0, '账号不存在或无权操作');
        }
        try {
            (new CheckinAccounts())->delete($account->toArray());
        } catch (PlatformException $exception) {
            return resultJson(0, $exception->getMessage());
        } catch (Throwable $exception) {
            return resultJson(0, '删除失败，请稍后重试');
        }
        return resultJson(1, '删除成功');
    }

    private function set(string $type)
    {
        $userId = trim((string)Request::post('user_id', ''));
        $account = $this->ownedAccount($type, $userId);
        if (!$account) {
            return resultJson(0, '账号不存在或无权操作');
        }
        $uid = (int)$account['uid'];
        $mode = (string)Request::post('act', '');
        if ($mode === 'timing') {
            $timing = trim((string)Request::post('timing', ''));
            $next = AutomaticSchedule::nextExecution($type, $userId, $timing);
            if ($timing !== '' && $next === null) {
                return resultJson(0, '挂机时间格式应为 HH:MM');
            }
            Accounts::where('id', (int)$account['id'])->update(['timing' => $timing !== '' ? $timing : null]);
            Jobs::where('uid', $uid)->where('type', $type)->where('user_id', $userId)->update(['nextExecute' => $next ?? 0]);
            return resultJson(1, $next === null ? '已关闭自动挂机' : '保存成功，首次将于 ' . date('m-d H:i', $next) . ' 左右执行');
        }
        if ($mode !== 'state' || (string)Request::post('do', '') !== PlatformRegistry::DAILY_TASK) {
            return resultJson(0, '不支持的操作');
        }
        Jobs::refreshJob($type, $userId, $uid);
        $job = Jobs::where('uid', $uid)->where('type', $type)->where('user_id', $userId)
            ->where('do', PlatformRegistry::DAILY_TASK)->find();
        if (!$job) {
            return resultJson(0, '任务已被管理员停用');
        }
        // The browser sends the desired state, so a double click cannot invert it.
        if ((string)Request::post('enabled', '') !== '1') {
            $job->save(['state' => 0]);
            return resultJson(1, '已暂停该任务');
        }
        if ((int)$account['state'] !== 1) {
            return resultJson(0, '账号已失效，请先更新账号');
        }
        $next = AutomaticSchedule::nextExecution($type, $userId, (string)($account['timing'] ?? ''));
        $job->save(['state' => 1, 'nextExecute' => $next === null ? 0
            : ((int)$job['nextExecute'] > time() ? (int)$job['nextExecute'] : $next)]);
        return resultJson(1, $next === null ? '已开启，设置挂机时间后自动执行' : '已开启该任务');
    }

    private function logs(string $type)
    {
        $userId = trim((string)Request::post('user_id', ''));
        if (!$this->ownedAccount($type, $userId)) {
            return json([]);
        }
        return json(TaskLogs::searchLogs($type, $userId) ?: []);
    }

    private function reExecute(string $type)
    {
        $userId = trim((string)Request::post('user_id', ''));
        $account = $this->ownedAccount($type, $userId);
        if (!$account) {
            return resultJson(0, '账号不存在或无权操作');
        }
        if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
            return resultJson(0, '请先设置挂机时间');
        }
        if ((int)$account['state'] !== 1) {
            return resultJson(0, '账号已失效，请先更新账号');
        }
        if ((int)$account['cooling'] > time()) {
            return resultJson(0, '请勿频繁提交，请 ' . ((int)$account['cooling'] - time()) . ' 秒后再试');
        }
        $enabled = Jobs::where('uid', (int)$account['uid'])->where('type', $type)->where('user_id', $userId)
            ->where('do', PlatformRegistry::DAILY_TASK)->where('state', 1)->count('id');
        if ($enabled === 0) {
            return resultJson(0, '任务已暂停，请先开启任务');
        }
        $cooldown = max(60, (int)(config('sys.reExecute_time') ?: 300));
        Accounts::where('id', (int)$account['id'])->update(['cooling' => time() + $cooldown]);
        Jobs::where('uid', (int)$account['uid'])->where('type', $type)->where('user_id', $userId)
            ->where('do', PlatformRegistry::DAILY_TASK)->where('state', 1)->update(['nextExecute' => time()]);
        return resultJson(1, '已安排立即执行，请稍后查看运行日志');
    }

    private function ownedAccount(string $type, string $userId)
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $userId) !== 1) {
            return null;
        }
        return Accounts::where('uid', (int)Session::get('user.uid'))->where('zid', 1)
            ->where('type', $type)->where('user_id', $userId)->find();
    }
}
