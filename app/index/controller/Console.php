<?php

namespace app\index\controller;

use app\Request;
use app\index\model\Jobs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\index\model\Accounts;
use app\service\BilibiliTaskExecutor;
use bilibili\Bilibili as BilibiliClient;
use think\facade\Session;
use Throwable;

class Console
{

    protected $middleware = [
        \app\middleware\CheckLoginUser::class
    ];

    public function index()
    {
        return view("console/index", [
            "notice" => \app\index\model\Notice::getNoticeList(),
            "quota_used" => Accounts::getMyAccountNum(),
            "agent" => is_Agent_Name(session("user.agent")),
            "user_count" => \app\index\model\Users::userCount(),
            "account_count" => Accounts::accountCount(),
            "job_count" => Jobs::jobCount(),
            "execute_count" => \app\index\model\Info::executeCount()
        ]);
    }

    public function agent()
    {
        if (session("user.agent") == 0) return view("common/alert", ["msg" => "权限不足", "url" => "/index/console"]);
        return view("console/agent/index", [
            "all" => \app\index\model\Kms::getMyList(),
            "used" => \app\index\model\Kms::getMyList(null, "used")
        ]);
    }

    public function shop($act = "")
    {
        switch ($act) {
            case "quota" :
                return view("console/shop/quota");
                break;
            case "vip" :
                return view("console/shop/vip");
                break;
            case "agent" :
                return view("console/shop/agent");
                break;
            case "money" :
                return view("console/shop/money");
                break;
            case "card" :
                return view("console/shop/card");
                break;
            case "site" :
                if (config("sys.is_site") != 1) return view("common/alert", ["msg" => "未开启自助开通分站", "url" => "/index/console"]);
                return view("console/shop/site", ["site_url" => explode(PHP_EOL, config("sys.site_url"))]);
                break;
        }
    }

    public function bilibili($act = "", $mid = "")
    {
        switch ($act) {
            case "add" :
                return view("console/bilibili/add");
                break;
            case "list" :
                return view("console/bilibili/list", ["list" => $this->bilibiliAccountList()]);
                break;
            case "info" :
                return $this->bilibiliInfo((string)$mid);
                break;
        }
    }

    private function bilibiliAccountList(): array
    {
        $result = [];
        $accounts = Accounts::getMyList('bilibili');
        if (!$accounts) {
            return $result;
        }

        foreach ($accounts as $account) {
            $profile = BilibiliTaskExecutor::decodeSerializedArray((string)$account['data']);
            $profile = is_array($profile) ? $profile : [];
            $mid = trim((string)($profile['mid'] ?? $account['user_id'] ?? ''));
            if ($mid === '') {
                continue;
            }
            $result[] = [
                'mid' => $mid,
                'nickname' => trim((string)($profile['nickname'] ?? '')) ?: '哔哩哔哩用户 ' . $mid,
                'avatar' => (string)($profile['avatar'] ?? ''),
                'state' => (int)$account['state'],
                'addtime' => (string)$account['addtime'],
            ];
        }
        return $result;
    }

    private function bilibiliInfo(string $mid)
    {
        $mid = trim($mid);
        $uid = (int)Session::get('user.uid');
        if ($mid === '' || !ctype_digit($mid)) {
            return view('common/alert', ['msg' => '账号参数错误', 'url' => '/index/console/bilibili/list.html']);
        }

        $account = Accounts::where('type', 'bilibili')
            ->where('user_id', $mid)
            ->where('uid', $uid)
            ->find();
        if (!$account) {
            return view('common/alert', ['msg' => '账号不存在或无权访问', 'url' => '/index/console/bilibili/list.html']);
        }

        $storedProfile = BilibiliTaskExecutor::decodeSerializedArray((string)$account['data']);
        $credentials = is_array($storedProfile)
            ? BilibiliTaskExecutor::normalizeAccountData($storedProfile)
            : null;
        if ($storedProfile === null || $credentials === null || $credentials['mid'] !== $mid) {
            return view('common/alert', ['msg' => '账号凭据损坏，请重新登录', 'url' => '/index/console/bilibili/add.html']);
        }

        $profile = [
            'mid' => $mid,
            'nickname' => trim((string)($storedProfile['nickname'] ?? '')) ?: '哔哩哔哩用户 ' . $mid,
            'avatar' => (string)($storedProfile['avatar'] ?? ''),
        ];
        $levelInfo = null;
        $infoWarning = '';
        try {
            $bilibili = new BilibiliClient(
                $credentials['mid'],
                $credentials['mid_md5'],
                $credentials['token'],
                $credentials['csrf'],
                $credentials['access_key'],
                ['sid' => $credentials['sid']]
            );
            $nav = $bilibili->sdk()->nav();
            $navData = is_array($nav['data'] ?? null) ? $nav['data'] : [];
            $loggedOut = (($nav['code'] ?? -1) === 0
                    && array_key_exists('isLogin', $navData)
                    && empty($navData['isLogin']))
                || $bilibili->sdk()->isAuthenticationFailure($nav);
            if ($loggedOut) {
                Accounts::where('type', 'bilibili')->where('user_id', $mid)->where('uid', $uid)->update(['state' => 0]);
                Jobs::where('type', 'bilibili')->where('user_id', $mid)->where('uid', $uid)->update(['state' => -1]);
                return view('common/alert', ['msg' => '登录状态已失效，请重新登录', 'url' => '/index/console/bilibili/add.html']);
            }
            if (($nav['code'] ?? -1) === 0 && !empty($navData['isLogin'])) {
                $level = is_array($navData['level_info'] ?? null) ? $navData['level_info'] : [];
                $currentLevel = max(0, (int)($level['current_level'] ?? 0));
                $currentExp = max(0, (int)($level['current_exp'] ?? 0));
                $nextExp = max($currentExp, (int)($level['next_exp'] ?? $currentExp));
                $levelInfo = [
                    'current_level' => $currentLevel,
                    'next_level' => min(6, $currentLevel + 1),
                    'current_exp' => $currentExp,
                    'remaining_exp' => max(0, $nextExp - $currentExp),
                    'money' => (string)($navData['money'] ?? '0'),
                ];
            } else {
                $infoWarning = '暂时无法获取等级信息：' . (string)($nav['message'] ?? '上游服务异常');
            }
        } catch (Throwable $exception) {
            $infoWarning = '暂时无法获取等级信息，请稍后刷新';
        }

        Jobs::refreshJob('bilibili', $mid);
        Jobs::where('type', 'bilibili')
            ->where('user_id', $mid)
            ->where('uid', $uid)
            ->whereIn('do', array_keys(BilibiliTaskExecutor::OFFLINE_TASKS))
            ->update(['state' => 0]);
        $jobsByTask = [];
        foreach (Jobs::where('type', 'bilibili')->where('user_id', $mid)->where('uid', $uid)->select() as $job) {
            $jobsByTask[(string)$job['do']] = $job;
        }
        $taskRows = [];
        foreach (Tasks::getTaskList('bilibili') as $task) {
            $taskName = (string)$task['execute_name'];
            $offlineReason = BilibiliTaskExecutor::offlineReason($taskName);
            $job = $jobsByTask[$taskName] ?? null;
            $config = $job
                ? BilibiliTaskExecutor::decodeSerializedArray((string)($job['data'] ?? ''))
                : [];
            $config = $this->bilibiliViewConfig($taskName, is_array($config) ? $config : []);
            $lastExecute = $job ? strtotime((string)($job['lastExecute'] ?? '')) : false;
            $taskRows[] = [
                'execute_name' => $taskName,
                'name' => (string)$task['name'],
                'describe' => (string)$task['describe'],
                'icon' => (string)$task['icon'],
                'more' => !empty($task['more']),
                'is_global' => $taskName === 'globalroom',
                'offline' => $offlineReason !== null,
                'offline_reason' => $offlineReason ?? '',
                'last_execute' => $lastExecute === false ? '--' : date('m-d H:i', $lastExecute),
                'job_state' => $job ? (int)$job['state'] : 0,
                'user_id' => $mid,
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ];
        }

        return view('console/bilibili/info', [
            'data' => $account,
            'a_data' => $profile,
            'timing' => (string)($account['timing'] ?? ''),
            'level_info' => $levelInfo,
            'info_warning' => $infoWarning,
            'task_rows' => $taskRows,
        ]);
    }

    private function bilibiliViewConfig(string $task, array $config): array
    {
        if ($task === 'globalroom') {
            $roomId = trim((string)($config['global_room'] ?? ''));
            return $roomId !== '' && ctype_digit($roomId) && (int)$roomId > 0
                ? ['global_room' => $roomId]
                : [];
        }
        if ($task === 'coinadd') {
            $mode = (string)($config['add_coin_mode'] ?? '');
            $count = (int)($config['add_coin_num'] ?? 0);
            return in_array($mode, ['random', 'fixed'], true) && $count >= 1 && $count <= 5
                ? ['add_coin_mode' => $mode, 'add_coin_num' => $count]
                : [];
        }
        return [];
    }

    public function netease($act = "", $user_id = "")
    {
        switch ($act) {
            case "add" :
                return view("console/netease/add");
                break;
            case "list" :
                return view("console/netease/list", ["list" => Accounts::getMyList("netease")]);
                break;
            case "info" :
                return view("console/netease/info", ["data" => Accounts::findByUserId($user_id)]);
                break;
        }
    }

    public function sport($act = "", $uid = "")
    {
        // Compatibility guard for deployments that still allow controller auto-routing.
        return response('Not Found', 404);
    }

    public function heybox($act = "", $uid = "")
    {
        switch ($act) {
            case "add" :
                return view("console/heybox/add");
                break;
            case "list" :
                return view("console/heybox/list", ["list" => Accounts::getMyList("heybox")]);
                break;
            case "info" :
                return view("console/heybox/info", ["data" => Accounts::findByUserId($uid)]);
                break;
        }
    }

    public function epic($act = "")
    {
        switch ($act) {
            case "weeklygame" :
                $job = Jobs::where("zid", "=", WEB_ID)->where("uid", "=", session("user.uid"))->where("type", "=", "epic")->where("do", "=", "weeklyGameNotify")->find();
                if (!$job) {
                    $job = [
                        "user_id" => "",
                        "uid" => session("user.uid"),
                        "zid" => WEB_ID,
                        "type" => "epic",
                        "do" => "weeklyGameNotify",
                        "state" => 0,
                        "nextExecute" => time(),
                        "data" => serialize([
                            "timing" => "",
                        ])
                    ];
                    Jobs::insert($job);
                }
                return view("console/epic/weeklyGame", [
                    "job" => $job,
                    "list" => (new \epic\Epic)->getWeeklyFreeGames()
                ]);
                break;
        }
    }

    public function qrcode($act = "")
    {
        switch ($act) {
            case "create" :
                return view("console/qrcode/create");
                break;
            case "list" :
                return view("console/qrcode/list", ["list" => Accounts::getMyList("qrcode")]);
                break;
        }
    }

    public function user($act = "")
    {
        switch ($act) {
            case "profile" :
                return view("console/user/profile");
                break;
            case "faq" :
                return view("console/user/faq", ["webTitle" => "帮助中心"]);
                break;
        }
    }

    public function bind(Request $request)
    {
        $openid = $request->post("openid");
        if (empty($openid)) return view("common/alert", ["msg" => "非法请求", "url" => "/index/console"]);
        if (session("user.token") != "") return view("common/alert", ["msg" => "请勿重复绑定", "url" => "/index/console"]);
        $row = Users::where("token", "=", $openid)->find();
        if ($row) return view("common/alert", ["msg" => "该快捷方式已被其他用户绑定", "url" => "/index/console"]);
        if (!Users::updateByUid(session("user.uid"), ["token" => $openid])) return view("common/alert", ["msg" => "绑定失败", "url" => "/index/console"]);
        Users::updateMyInfo();
        return view("common/alert", ["msg" => "绑定成功", "url" => "/index/console"]);
    }

}

?>
