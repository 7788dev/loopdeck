<?php

namespace app\index\controller;

use app\index\model\Accounts;
use app\index\model\Captcha;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\service\AutomaticSchedule;
use netease\Netease as NeteaseClient;
use netease\QRcode;
use think\exception\ValidateException;
use think\facade\Request;
use think\facade\Session;

/**
 * Clean NetEase-only AJAX controller.
 *
 * This replaces the NetEase branch that was embedded in the encrypted Ajax
 * controller without changing any of the public URLs used by the console.
 */
class Netease
{
    protected $middleware = [
        \app\middleware\CheckLoginUser::class,
        \app\middleware\CheckAjaxRequest::class,
    ];

    public function handle($act = null)
    {
        switch ($act) {
            case 'add':
                return resultJson(0, '账号密码登录已关闭，请使用扫码登录');
            case 'getQrimg':
                return $this->getQrimg();
            case 'qrLogin':
                return $this->qrLogin();
            case 'verifyCheck':
                return $this->verifyCheck();
            case 'delete':
                return $this->delete();
            case 'set':
                return $this->set();
            case 'logs':
                return $this->logs();
            case 'reExecute':
                return $this->reExecute();
            case 'listen':
                return $this->listen();
            default:
                return resultJson(0, '不支持的操作');
        }
    }

    private function getQrimg()
    {
        try {
            $client = new NeteaseClient();
            $key = $client->get_qr_key();
            if ($key === '') {
                return resultJson(0, '获取二维码登录密钥失败');
            }

            $url = 'https://music.163.com/login?codekey=' . rawurlencode($key);
            return resultJson(1, '获取二维码成功', [
                'key' => $key,
                'qrimg' => $this->renderQrBase64($url),
            ]);
        } catch (\Throwable $exception) {
            return resultJson(0, '二维码生成失败，请稍后重试');
        }
    }

    private function qrLogin()
    {
        $key = trim((string)Request::post('key', ''));
        if ($key === '') {
            return resultJson(0, '参数错误');
        }
        $login = (new NeteaseClient())->qrLogin($key);
        $code = (int)($login['code'] ?? 0);

        // 8810 risk control: hand the browser a second security-verify QR built
        // from the toast unikey. The user scans it with an already-logged-in app
        // and the frontend then polls /netease/verifyCheck.
        if ($code === 8810 && !empty($login['data']['verify_unikey'])) {
            $data = $login['data'];
            $data['verify_qrimg'] = $this->renderQrBase64($data['verify_qrurl'] ?? '');
            return resultJson(8810, $login['message'] ?? '当前网络环境存在风险，请扫码完成安全验证', $data);
        }

        if ($code !== 200) {
            return resultJson($code, $login['message'] ?? '二维码登录失败');
        }
        return $this->storeAccount($login['data']);
    }

    /**
     * Poll the 8810 security-verify QR. Shares the success path with qrLogin.
     */
    private function verifyCheck()
    {
        $verifyUnikey = trim((string)Request::post('verify_unikey', ''));
        if ($verifyUnikey === '') {
            return resultJson(0, '参数错误');
        }
        $login = (new NeteaseClient())->qrCheckVerify($verifyUnikey);
        $code = (int)($login['code'] ?? 0);
        if ($code === 200) {
            return $this->storeAccount($login['data']);
        }
        // -1 (801/802 waiting) and 800 (expired) keep their codes so the frontend
        // can keep polling or prompt a re-fetch; anything else surfaces as-is.
        return resultJson($code === -1 ? -1 : $code, $login['message'] ?? '二维码登录失败');
    }

    /**
     * Render a QR image as base64 for inline display in the console. Mirrors the
     * getQrimg() ob_start + Qrcode::png pattern.
     */
    private function renderQrBase64(string $url): string
    {
        if ($url === '') {
            return '';
        }
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
        $userId = $data['user_id'] ?? null;
        if (!$userId) {
            return resultJson(0, '未获取到网易云用户ID');
        }
        if (Accounts::where('type', 'netease')
            ->where('user_id', $userId)
            ->where('uid', '<>', Session::get('user.uid'))
            ->find()) {
            return resultJson(-1, '系统已存在该账号，无法继续添加');
        }

        $data['uid'] = Session::get('user.uid');
        $data['type'] = 'netease';
        $data['user_id'] = $userId;
        try {
            validate(\app\index\validate\Accounts::class)->scene('add')->check($data);
        } catch (ValidateException $e) {
            return resultJson(-1, $e->getMessage());
        }
        return Accounts::add('netease', $userId, $data);
    }

    private function delete()
    {
        $userId = Request::post('user_id');
        if (!$userId || !Accounts::findByUserId('netease', $userId)) {
            return resultJson(0, '账号不存在或无权操作');
        }
        $accountDeleted = Accounts::delByUserId('netease', $userId);
        $jobsDeleted = Jobs::delJob('netease', $userId);
        $logsDeleted = TaskLogs::deleteLogs('netease', $userId);
        return $accountDeleted && $jobsDeleted && $logsDeleted
            ? resultJson(1, '删除成功')
            : resultJson(0, '删除失败');
    }

    private function set()
    {
        $data = Request::post();
        $userId = $data['user_id'] ?? null;
        if (!$userId || !Accounts::findByUserId('netease', $userId)) {
            return resultJson(0, '账号不存在或无权操作');
        }

        switch ($data['act'] ?? '') {
            case 'zt':
                Jobs::refreshJob('netease', $userId);
                if (Tasks::checkTaskPower($data['do'] ?? '', 'netease') && empty(Session::get('user.vip_start'))) {
                    return resultJson(-1, '您需要开通VIP会员才可以使用该功能');
                }
                return Jobs::switchState('netease', $userId, $data['do'] ?? '')
                    ? resultJson(1, '修改成功')
                    : resultJson(0, '修改失败');

            case 'timing':
                $timing = trim((string)($data['timing'] ?? ''));
                $next = AutomaticSchedule::nextExecution('netease', (string)$userId, $timing);
                if ($timing !== '' && $next === null) {
                    return resultJson(0, '挂机时间格式错误');
                }
                Accounts::where('type', 'netease')
                    ->where('user_id', $userId)
                    ->where('uid', Session::get('user.uid'))
                    ->update(['timing' => $timing !== '' ? $timing : null]);
                Jobs::where('type', 'netease')
                    ->where('user_id', $userId)
                    ->where('uid', Session::get('user.uid'))
                    ->update(['nextExecute' => $next ?? 0]);
                return resultJson(1, $next === null ? '已关闭自动挂机' : '保存成功');

            default:
                $config = json_decode((string)($data['config'] ?? '{}'), true);
                if (!is_array($config)) {
                    return resultJson(0, '任务配置格式错误');
                }
                $updated = Jobs::where('type', 'netease')
                    ->where('user_id', $userId)
                    ->where('uid', Session::get('user.uid'))
                    ->where('do', $data['do'] ?? '')
                    ->update(['data' => serialize($config)]);
                return $updated !== false ? resultJson(1, '保存成功') : resultJson(0, '保存失败');
        }
    }

    private function logs()
    {
        $userId = Request::post('user_id');
        if (!$userId || !Accounts::findByUserId('netease', $userId)) {
            return resultJson(0, '账号不存在或无权操作');
        }
        return TaskLogs::searchLogs('netease', $userId);
    }

    private function reExecute()
    {
        $userId = Request::post('user_id');
        $account = $userId ? Accounts::findByUserId('netease', $userId) : false;
        if (!$account) {
            return resultJson(0, '非法操作');
        }
        if (!AutomaticSchedule::isConfigured((string)($account['timing'] ?? ''))) {
            return resultJson(0, '请先设置挂机时间');
        }

        $query = Jobs::where('user_id', $userId)
            ->where('type', 'netease')
            ->where('uid', Session::get('user.uid'))
            ->where('state', 1);
        if ($query->count() === 0) {
            return resultJson(1, '没有需要补挂的任务');
        }
        if ((int)$account['cooling'] > time()) {
            return resultJson(1, '请勿频繁提交，请等待' . ((int)$account['cooling'] - time()) . '秒后再操作');
        }

        $cooldown = (int)(config('sys.reExecute_time') ?: 300);
        Accounts::where('user_id', $userId)
            ->where('type', 'netease')
            ->where('uid', Session::get('user.uid'))
            ->update(['cooling' => time() + $cooldown]);
        $query->update(['nextExecute' => time()]);
        return resultJson(1, '申请补挂成功，请稍后查看任务运行情况');
    }

    private function listen()
    {
        if ((int)config('sys.is_netease_tool') !== 1) {
            return resultJson(0, '网易云播放工具未开启');
        }
        $userId = trim((string)Request::post('user_id', ''));
        $songId = trim((string)Request::post('songid', ''));
        $timesValue = trim((string)Request::post('times', ''));
        $account = $userId === '' ? null : Accounts::where('type', 'netease')
            ->where('user_id', $userId)
            ->where('uid', Session::get('user.uid'))
            ->where('state', 1)
            ->find();
        if (!$account) {
            return resultJson(0, '网易云账号不存在、已失效或无权操作');
        }
        if (!ctype_digit($songId)
            || strlen($songId) > 18
            || (int)$songId <= 0
            || !ctype_digit($timesValue)
        ) {
            return resultJson(0, '参数错误');
        }
        $times = (int)$timesValue;
        if ($times < 1 || $times > 300) {
            return resultJson(0, '次数必须在1到300之间');
        }

        try {
            $cookies = safe_unserialize_array((string)$account['data']);
        } catch (\Throwable $exception) {
            return resultJson(0, '网易云账号凭据损坏，请重新登录');
        }
        if (empty($cookies['user_id']) || empty($cookies['csrf']) || empty($cookies['musicu'])) {
            return resultJson(0, '网易云账号凭据不完整，请重新登录');
        }

        $where = [
            ['type', '=', '0'],
            ['send', '=', $cookies['user_id']],
            ['time', '>', time() - 86400],
        ];
        $limit = (int)config('sys.netease_tool_limit');
        if ($limit > 0 && Captcha::where($where)->count() >= $limit) {
            return resultJson(-1, '当前账号今日使用次数过多，请24小时后再试');
        }

        $client = new NeteaseClient(
            (string)$cookies['user_id'],
            (string)$cookies['csrf'],
            (string)$cookies['musicu'],
            [
                'songid' => $songId,
                'times' => $times,
                'sdk' => [
                    'connect_timeout' => 4.0,
                    'timeout' => 8.0,
                ],
            ]
        );
        $result = $client->listen();
        if ((int)($result['code'] ?? 0) !== 200) {
            return resultJson(0, trim((string)($result['message'] ?? '听歌任务执行失败')) ?: '听歌任务执行失败');
        }

        Captcha::add([
            'type' => 0,
            'code' => $songId,
            'send' => $cookies['user_id'],
            'ip' => real_ip(),
        ]);
        return resultJson(1, (string)$result['message']);
    }
}
