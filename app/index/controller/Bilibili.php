<?php

namespace app\index\controller;

use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use bilibili\Bilibili as BilibiliClient;
use netease\Qrcode;
use think\exception\ValidateException;
use think\facade\Request;
use think\facade\Session;

class Bilibili
{
    protected $middleware = [
        \app\middleware\CheckLoginUser::class,
        \app\middleware\CheckAjaxRequest::class,
    ];

    public function handle($act = null)
    {
        switch ($act) {
            case 'geetest_captcha':
                return $this->geetestCaptcha();
            case 'getQrimg':
                return $this->getQrimg();
            case 'qrLogin':
                return $this->qrLogin();
            case 'sendSms':
                return $this->sendSms();
            case 'smsLogin':
                return $this->smsLogin();
            case 'delete':
                return $this->delete();
            case 'set':
                return $this->set();
            case 'logs':
                return $this->logs();
            case 'reExecute':
                return $this->reExecute();
            default:
                return resultJson(0, '不支持的操作');
        }
    }

    private function geetestCaptcha()
    {
        $result = (new BilibiliClient())->geetest();
        return ($result['code'] ?? 0) === 1
            ? resultJson(1, $result['message'] ?? '获取成功', $result['data'] ?? [])
            : resultJson(0, $result['message'] ?? '获取人机验证参数失败');
    }

    private function getQrimg()
    {
        try {
            $result = (new BilibiliClient())->getQrimg();
            if (($result['code'] ?? 0) !== 1 || empty($result['url']) || empty($result['qrcode_key'])) {
                return resultJson(0, $result['message'] ?? '获取二维码失败');
            }
            $image = $this->renderQrBase64((string)$result['url']);
        } catch (\Throwable $exception) {
            return resultJson(0, '二维码生成失败，请稍后重试');
        }
        return resultJson(1, '获取二维码成功', [
            'qrcode_key' => (string)$result['qrcode_key'],
            'oauthKey' => (string)$result['qrcode_key'],
            'qrimg' => $image,
            'expires_in' => 180,
        ]);
    }

    private function qrLogin()
    {
        $key = trim((string)Request::post('qrcode_key', Request::post('oauthKey', '')));
        if ($key === '') {
            return resultJson(0, '二维码登录密钥不能为空');
        }
        $result = (new BilibiliClient())->qrLogin($key);
        if (($result['code'] ?? 0) === 1) {
            return $this->storeAccount($result['data'] ?? []);
        }
        return resultJson((int)($result['code'] ?? 0), $result['message'] ?? '二维码登录失败');
    }

    private function sendSms()
    {
        $phone = trim((string)Request::post('phone', ''));
        $cid = max(1, (int)Request::post('cid', 1));
        if (!preg_match('/^\d{5,20}$/', $phone)) {
            return resultJson(0, '手机号格式错误');
        }
        $captcha = [
            'token' => trim((string)Request::post('token', '')),
            'challenge' => trim((string)Request::post('challenge', '')),
            'validate' => trim((string)Request::post('validate', '')),
            'seccode' => trim((string)Request::post('seccode', '')),
        ];
        if (in_array('', $captcha, true)) {
            return resultJson(0, '请先完成人机验证');
        }

        $result = (new BilibiliClient())->sendSms($phone, $captcha, $cid);
        return ($result['code'] ?? 0) === 1
            ? resultJson(1, $result['message'] ?? '短信验证码已发送', $result['data'] ?? [])
            : resultJson(0, $result['message'] ?? '短信验证码发送失败');
    }

    private function smsLogin()
    {
        $phone = trim((string)Request::post('phone', ''));
        $code = trim((string)Request::post('code', ''));
        $captchaKey = trim((string)Request::post('captcha_key', ''));
        $cid = max(1, (int)Request::post('cid', 1));
        if (!preg_match('/^\d{5,20}$/', $phone) || !preg_match('/^\d{4,8}$/', $code) || $captchaKey === '') {
            return resultJson(0, '短信登录参数错误');
        }

        $result = (new BilibiliClient())->smsLogin($phone, $code, $captchaKey, $cid);
        if (($result['code'] ?? 0) === 1) {
            return $this->storeAccount($result['data'] ?? []);
        }
        return resultJson(0, $result['message'] ?? '短信登录失败');
    }

    private function renderQrBase64(string $url): string
    {
        ob_start();
        try {
            Qrcode::png($url, false, QR_ECLEVEL_L, 8, 4);
            $image = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return base64_encode((string)$image);
    }

    private function storeAccount(array $data)
    {
        $mid = trim((string)($data['mid'] ?? ''));
        if ($mid === '' || !ctype_digit($mid)) {
            return resultJson(0, '未获取到哔哩哔哩用户ID');
        }
        if (Accounts::where('type', 'bilibili')
            ->where('user_id', $mid)
            ->where('uid', '<>', Session::get('user.uid'))
            ->find()) {
            return resultJson(-1, '系统已存在该账号，无法继续添加');
        }

        $accountData = [
            'nickname' => (string)($data['nickname'] ?? ''),
            'avatar' => (string)($data['avatar'] ?? ''),
            'mid' => $mid,
            'mid_md5' => (string)($data['mid_md5'] ?? ''),
            'token' => (string)($data['token'] ?? ''),
            'csrf' => (string)($data['csrf'] ?? ''),
            'sid' => (string)($data['sid'] ?? ''),
            'access_key' => (string)($data['access_key'] ?? ''),
            'refresh_token' => (string)($data['refresh_token'] ?? ''),
        ];
        foreach (['mid_md5', 'token', 'csrf'] as $required) {
            if ($accountData[$required] === '') {
                return resultJson(0, '登录响应缺少必要凭据：' . $required);
            }
        }

        $validateData = [
            'uid' => Session::get('user.uid'),
            'type' => 'bilibili',
            'user_id' => $mid,
        ];
        try {
            validate(\app\index\validate\Accounts::class)->scene('add')->check($validateData);
        } catch (ValidateException $exception) {
            return resultJson(-1, $exception->getMessage());
        }
        return Accounts::add('bilibili', $mid, $accountData);
    }

    private function delete()
    {
        $userId = trim((string)Request::post('user_id', Request::post('mid', '')));
        if (!$this->ownedAccount($userId)) {
            return resultJson(0, '账号不存在或无权操作');
        }
        $accountDeleted = Accounts::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->delete() !== false;
        $jobsDeleted = Jobs::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->delete() !== false;
        $logsDeleted = TaskLogs::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->delete() !== false;
        return $accountDeleted && $jobsDeleted && $logsDeleted
            ? resultJson(1, '删除成功')
            : resultJson(0, '删除失败');
    }

    private function set()
    {
        $data = Request::post();
        $userId = trim((string)($data['user_id'] ?? ''));
        if (!$this->ownedAccount($userId)) {
            return resultJson(0, '账号不存在或无权操作');
        }

        if (($data['act'] ?? '') === 'timing') {
            return $this->setTiming($userId, trim((string)($data['timing'] ?? '')));
        }

        $do = trim((string)($data['do'] ?? ''));
        $offlineReason = BilibiliTaskExecutor::offlineReason($do);
        if ($offlineReason !== null) {
            Jobs::where('type', 'bilibili')
                ->where('user_id', $userId)
                ->where('uid', Session::get('user.uid'))
                ->where('do', $do)
                ->update(['state' => 0]);
            return resultJson(0, $offlineReason);
        }
        $task = $this->activeTask($do);
        if (!$task) {
            return resultJson(0, '任务不存在或已停用');
        }

        if (($data['act'] ?? '') === 'zt') {
            Jobs::refreshJob('bilibili', $userId);
            if ((int)$task['vip'] === 1 && empty(Session::get('user.vip_start'))) {
                return resultJson(-1, '您需要开通VIP会员才可以使用该功能');
            }
            return Jobs::switchState($userId, $do)
                ? resultJson(1, '修改成功')
                : resultJson(0, '修改失败');
        }

        $config = json_decode((string)($data['config'] ?? '{}'), true);
        if (!is_array($config)) {
            return resultJson(0, '任务配置格式错误');
        }
        $config = $this->normalizeTaskConfig($do, $config);
        if ($config === null) {
            return resultJson(0, '任务配置参数错误');
        }
        $updated = Jobs::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->where('do', $do)
            ->update(['data' => serialize($config)]);
        return $updated !== false ? resultJson(1, '保存成功') : resultJson(0, '保存失败');
    }

    private function setTiming(string $userId, string $timing)
    {
        $job = Jobs::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->whereNotNull('lastExecute')
            ->find();
        if (!$job) {
            return resultJson(0, '请先等待系统执行后再设定挂机时间');
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timing)) {
            return resultJson(0, '挂机时间格式错误');
        }
        $next = strtotime($timing . ' +1 day');
        if ($next === false) {
            return resultJson(0, '挂机时间格式错误');
        }
        Accounts::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->update(['timing' => $timing]);
        Jobs::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->update(['nextExecute' => $next]);
        return resultJson(1, '保存成功');
    }

    private function normalizeTaskConfig(string $do, array $config): ?array
    {
        if ($do === 'globalroom') {
            $roomId = trim((string)($config['global_room'] ?? ''));
            return ctype_digit($roomId) && (int)$roomId > 0 ? ['global_room' => $roomId] : null;
        }
        if ($do === 'coinadd') {
            $mode = (string)($config['add_coin_mode'] ?? '');
            $number = (int)($config['add_coin_num'] ?? 0);
            if (!in_array($mode, ['random', 'fixed'], true) || $number < 1 || $number > 5) {
                return null;
            }
            return ['add_coin_mode' => $mode, 'add_coin_num' => $number];
        }
        return $config === [] ? [] : null;
    }

    private function logs()
    {
        $userId = trim((string)Request::post('user_id', ''));
        if (!$this->ownedAccount($userId)) {
            return resultJson(0, '账号不存在或无权操作');
        }
        return TaskLogs::searchLogs('bilibili', $userId);
    }

    private function reExecute()
    {
        $userId = trim((string)Request::post('user_id', ''));
        $account = $this->ownedAccount($userId);
        if (!$account) {
            return resultJson(0, '非法操作');
        }
        $query = Jobs::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->where('state', 1)
            ->whereIn('do', BilibiliTaskExecutor::TASKS);
        if ($query->count() === 0) {
            return resultJson(1, '没有需要补挂的任务');
        }
        if ((int)$account['cooling'] > time()) {
            return resultJson(1, '请勿频繁提交，请等待' . ((int)$account['cooling'] - time()) . '秒后再操作');
        }

        $cooldown = (int)(config('sys.reExecute_time') ?: 300);
        Accounts::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->update(['cooling' => time() + $cooldown]);
        $query->update(['nextExecute' => time()]);
        return resultJson(1, '申请补挂成功，请稍后查看任务运行情况');
    }

    private function ownedAccount(string $userId)
    {
        if ($userId === '') {
            return false;
        }
        return Accounts::where('type', 'bilibili')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->find();
    }

    private function activeTask(string $do)
    {
        if ($do === '') {
            return false;
        }
        return Tasks::where('type', 'bilibili')
            ->where('execute_name', $do)
            ->where('state', 1)
            ->find();
    }
}
