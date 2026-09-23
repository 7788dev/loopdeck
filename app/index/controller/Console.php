<?php

namespace app\index\controller;

use app\Request;
use app\index\model\Jobs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\index\model\Accounts;
use app\service\AccountSnapshot;
use app\service\BilibiliTaskExecutor;
use app\service\CheckinAccounts;
use app\service\CheckinTaskExecutor;
use app\service\PlatformRegistry;
use app\service\UserNotificationSettings;
use think\facade\Session;
use Throwable;

class Console
{

    protected $middleware = [
        \app\middleware\CheckLoginUser::class
    ];

    public function index()
    {
        return view("console/index", array_merge(\app\service\ConsoleStatistics::totals(), [
            "notice" => \app\index\model\Notice::getNoticeList(),
            "quota_used" => Accounts::getMyAccountNum(),
        ]));
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
            case "money" :
                return view("console/shop/money");
                break;
            case "card" :
                return view("console/shop/card");
                break;
            default:
                return response('页面不存在', 404);
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
        $snapshot = (new \app\service\AccountSnapshot())->read($account->toArray());
        // Create missing job rows before rendering: a switch drawn for a
        // missing row shows "off" although toggling it would enable the task.
        Jobs::refreshJob('bilibili', $mid, $uid);
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
                'describe' => $offlineReason !== null
                    ? $offlineReason
                    : (string)$task['describe'],
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
            'snapshot' => $snapshot,
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
            case "tool" :
                if ((int)config('sys.is_netease_tool') !== 1) {
                    return view('common/alert', [
                        'msg' => '管理员尚未开启听歌任务工具',
                        'url' => '/index/console/netease/list.html',
                    ]);
                }
                return view('console/netease/tool', [
                    'accounts' => $this->neteaseToolAccounts(),
                    'daily_limit' => max(0, (int)config('sys.netease_tool_limit')),
                ]);
                break;
            case "info" :
                $account = Accounts::findByUserId('netease', $user_id);
                if ($account) {
                    Jobs::refreshJob('netease', $user_id, (int)$account['uid']);
                }
                return $this->neteaseInfo($account);
                break;
        }
    }

    /**
     * 组装网易云账号详情页数据。原先模板里 {php} 块会实例化模型、在 foreach
     * 内逐条 getJobInfo（N+1）并同步调用上游 getMusicUserInfo（30s 超时会拖住
     * 整页），这里统一移到控制器：一次 select 建 job 映射，上游信息失败时页面
     * 降级展示而不阻塞。
     */
    private function neteaseInfo($account)
    {
        if (!$account) {
            return view("common/alert", ["msg" => "账号不存在或无权查看", "url" => "/index/console/netease/list"]);
        }

        $a_data = safe_unserialize_array((string)$account['data']);
        $userId = trim((string)($a_data['user_id'] ?? $account['user_id'] ?? ''));
        $timing = (string)($account['timing'] ?? '');

        $snapshot = (new \app\service\AccountSnapshot())->read($account->toArray());

        // 一次 select 建 job 映射，替代模板内每任务一条 getJobInfo
        $jobsByTask = [];
        foreach (Jobs::where('type', 'netease')->where('user_id', $userId)->where('uid', (int)$account['uid'])->select() as $job) {
            $jobsByTask[(string)$job['do']] = $job;
        }
        $taskRows = [];
        foreach (Tasks::getTaskList('netease') as $task) {
            $job = $jobsByTask[(string)$task['execute_name']] ?? null;
            $config = $job && $job['data'] ? json_encode(safe_unserialize_array((string)$job['data'])) : '[]';
            $taskRows[] = [
                'icon' => (string)$task['icon'],
                'name' => (string)$task['name'],
                'describe' => (string)$task['describe'],
                'more' => !empty($task['more']),
                'execute_name' => (string)$task['execute_name'],
                'config' => $config ?: '[]',
                'last_execute' => $job ? (string)($job['lastExecute'] ?? '') : '',
                'next_execute' => $job && (int)$job['state'] === 1 && (int)$job['nextExecute'] > 0
                    ? date('m-d H:i:s', (int)$job['nextExecute']) : '',
                'job_state' => $job ? (int)$job['state'] : 0,
            ];
        }

        return view("console/netease/info", [
            "data" => $account,
            "a_data" => [
                'user_id' => $userId,
                'avatar' => (string)($a_data['avatar'] ?? ''),
                'nickname' => (string)($a_data['nickname'] ?? ''),
            ],
            "timing" => $timing,
            "snapshot" => $snapshot,
            "signature" => $snapshot['signature'],
            "task_rows" => $taskRows,
        ]);
    }

    private function neteaseToolAccounts(): array
    {
        $result = [];
        $accounts = Accounts::getMyList('netease');
        if (!$accounts) {
            return $result;
        }

        foreach ($accounts as $account) {
            if ((int)$account['state'] !== 1) {
                continue;
            }
            try {
                $profile = safe_unserialize_array((string)$account['data']);
            } catch (Throwable $exception) {
                continue;
            }
            $userId = trim((string)($profile['user_id'] ?? $account['user_id'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $result[] = [
                'user_id' => $userId,
                'nickname' => trim((string)($profile['nickname'] ?? '')) ?: '网易云用户 ' . $userId,
            ];
        }
        return $result;
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
                return $this->heyboxInfo($uid);
                break;
        }
    }

    /**
     * 组装小黑盒账号详情页数据：任务/任务状态一次查询映射（替代模板内
     * 循环逐条 getJobInfo），lastExecute 空值时展示"尚未执行"而非 1970。
     */
    private function heyboxInfo($uid)
    {
        $account = Accounts::findByUserId('heybox', $uid);
        if (!$account) {
            return view("common/alert", ["msg" => "账号不存在或无权查看", "url" => "/index/console/heybox/list"]);
        }
        Jobs::refreshJob('heybox', $uid, (int)$account['uid']);

        $a_data = safe_unserialize_array((string)$account['data']);
        $jobsByTask = [];
        foreach (Jobs::where('type', 'heybox')->where('user_id', $uid)->where('uid', (int)$account['uid'])->select() as $job) {
            $jobsByTask[(string)$job['do']] = $job;
        }
        $taskRows = [];
        foreach (Tasks::getTaskList('heybox') as $task) {
            $job = $jobsByTask[(string)$task['execute_name']] ?? null;
            $config = $job && $job['data'] ? json_encode(safe_unserialize_array((string)$job['data'])) : '[]';
            $taskRows[] = [
                'icon' => (string)$task['icon'],
                'name' => (string)$task['name'],
                'describe' => (string)$task['describe'],
                'more' => !empty($task['more']),
                'execute_name' => (string)$task['execute_name'],
                'config' => $config ?: '[]',
                'last_execute' => $job ? (string)($job['lastExecute'] ?? '') : '',
                'job_state' => $job ? (int)$job['state'] : 0,
            ];
        }

        return view("console/heybox/info", [
            "data" => $account,
            "a_data" => [
                'avatar' => (string)($a_data['avatar'] ?? ''),
                'nickname' => (string)($a_data['displayname'] ?? ('小黑盒用户 ' . $uid)),
            ],
            "task_rows" => $taskRows,
        ]);
    }

    public function tieba($act = "", $user_id = "")
    {
        return $this->checkinPage('tieba', (string)$act, (string)$user_id);
    }

    public function quark($act = "", $user_id = "")
    {
        return $this->checkinPage('quark', (string)$act, (string)$user_id);
    }

    public function tianyi($act = "", $user_id = "")
    {
        return $this->checkinPage('tianyi', (string)$act, (string)$user_id);
    }

    public function aliyundrive($act = "", $user_id = "")
    {
        return $this->checkinPage('aliyundrive', (string)$act, (string)$user_id);
    }

    /** Pages of the daily check-in platforms (see app\service\PlatformRegistry). */
    private function checkinPage(string $type, string $act, string $userId)
    {
        $platform = PlatformRegistry::get($type);
        $listUrl = '/index/console/' . $type . '/list';
        switch ($act) {
            case 'add':
                // add/<user_id> re-authenticates an existing account in place.
                $current = null;
                if ($userId !== '') {
                    $account = $this->checkinAccount($type, $userId);
                    if (!$account) {
                        return view('common/alert', ['msg' => '账号不存在或无权操作', 'url' => $listUrl]);
                    }
                    $current = CheckinAccounts::display($account->toArray());
                }
                return view("console/{$type}/add", ['platform' => $platform, 'current' => $current]);
            case 'list':
                return view("console/{$type}/list", ['platform' => $platform] + $this->checkinList($type));
            case 'info':
                return $this->checkinInfo($type, $platform, $userId);
        }
        return view('common/alert', ['msg' => '页面不存在', 'url' => $listUrl]);
    }

    private function checkinList(string $type): array
    {
        $perPage = 12;
        $query = static fn() => Accounts::where('uid', (int)Session::get('user.uid'))->where('zid', 1)->where('type', $type);
        $total = (int)$query()->count('id');
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, (int)request()->get('page', 1)));
        $list = [];
        foreach ($query()->order('addtime desc')->order('id desc')->page($page, $perPage)->select() as $account) {
            $list[] = CheckinAccounts::display($account->toArray());
        }
        return ['list' => $list, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'prev_page' => max(1, $page - 1), 'next_page' => min($pages, $page + 1), 'page_numbers' => range(1, $pages)];
    }

    private function checkinInfo(string $type, array $platform, string $userId)
    {
        $account = $this->checkinAccount($type, $userId);
        if (!$account) {
            return view('common/alert', ['msg' => '账号不存在或无权查看', 'url' => '/index/console/' . $type . '/list']);
        }
        $uid = (int)$account['uid'];
        Jobs::refreshJob($type, $userId, $uid);
        $jobsByTask = [];
        foreach (Jobs::where('type', $type)->where('user_id', $userId)->where('uid', $uid)->select() as $job) {
            $jobsByTask[(string)$job['do']] = $job;
        }
        $taskRows = [];
        foreach (Tasks::getTaskList($type) as $task) {
            $job = $jobsByTask[(string)$task['execute_name']] ?? null;
            $nextExecute = $job ? (int)$job['nextExecute'] : 0;
            $taskRows[] = [
                'icon' => (string)$task['icon'],
                'name' => (string)$task['name'],
                'describe' => (string)$task['describe'],
                'execute_name' => (string)$task['execute_name'],
                'last_execute' => $job && (string)$job['lastExecute'] !== '' ? (string)$job['lastExecute'] : '尚未执行',
                'next_execute' => $job && (int)$job['state'] === 1 && $nextExecute > 0 ? date('m-d H:i', $nextExecute) : '',
                'job_state' => $job ? (int)$job['state'] : 0,
            ];
        }
        $row = $account->toArray();
        return view("console/{$type}/info", [
            'platform' => $platform,
            'account' => CheckinAccounts::display($row),
            'snapshot' => (new AccountSnapshot())->read($row),
            'summary' => CheckinTaskExecutor::summary($row),
            'task_rows' => $taskRows,
        ]);
    }

    private function checkinAccount(string $type, string $userId)
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $userId) !== 1) {
            return null;
        }
        return Accounts::where('uid', (int)Session::get('user.uid'))->where('zid', 1)
            ->where('type', $type)->where('user_id', $userId)->find();
    }

    public function epic($act = "")
    {
        if ($act !== 'weeklygame') {
            return response('Not Found', 404);
        }
        $job = Jobs::where('zid', WEB_ID)->where('uid', (int)Session::get('user.uid'))
            ->where('type', 'epic')->where('do', 'weeklyGameNotify')->find();
        return view('console/epic/weeklyGame', [
            'epic_enabled' => $job && (int)$job['state'] === 1,
            'epic_next' => $job && (int)$job['state'] === 1 && (int)$job['nextExecute'] > 0
                ? date('m-d H:i', (int)$job['nextExecute']) : '',
            'list' => (new \epic\Epic())->getWeeklyFreeGames(),
        ]);
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
                $notification = UserNotificationSettings::redact(UserNotificationSettings::defaults());
                $emailAvailable = false;
                $notificationError = '';
                $deliveries = [];
                try {
                    $uid = (int)Session::get('user.uid');
                    $webId = (int)Session::get('user.web_id');
                    $notification = (new UserNotificationSettings())->publicSettings($uid, $webId);
                    $emailAvailable = (new \app\service\NotificationSite())->get($webId)['email_available'];
                    $deliveries = (new \app\service\NotificationRepository())->recentMessages($uid, $webId);
                } catch (Throwable $exception) {
                    $notificationError = '推送设置暂不可用，请稍后刷新；个人资料与密码仍可正常修改。';
                }
                if ($notification['email_address'] === '') {
                    $notification['email_address'] = (string)Session::get('user.mail', '');
                }
                return view("console/user/profile", [
                    'webTitle' => '个人中心', 'notification' => $notification,
                    'notification_email_available' => $emailAvailable,
                    'notification_can_epic' => (int)strtotime((string)Session::get('user.vip_end', '')) > time(),
                    'notification_error' => $notificationError, 'notification_deliveries' => $deliveries,
                ]);
                break;
            case "notification" :
                return redirect('/index/console/user/profile#notifications');
                break;
            case "faq" :
                return view("console/user/faq", ["webTitle" => "帮助中心"]);
                break;
        }
    }

    public function bind(Request $request)
    {
        // A plain cross-site form used to be able to bind an attacker-chosen
        // shortcut to the victim's account, permanently.
        if (!$request->isPost() || is_cross_origin_request()) {
            return view("common/alert", ["msg" => "非法请求", "url" => "/index/console"]);
        }
        $openid = trim((string)$request->post("openid", ""));
        if ($openid === "" || strlen($openid) > 128 || preg_match('/\A[A-Za-z0-9_-]+\z/', $openid) !== 1) {
            return view("common/alert", ["msg" => "非法请求", "url" => "/index/console"]);
        }
        if (session("user.token") != "") return view("common/alert", ["msg" => "请勿重复绑定", "url" => "/index/console"]);
        $row = Users::where("token", "=", $openid)->find();
        if ($row) return view("common/alert", ["msg" => "该快捷方式已被其他用户绑定", "url" => "/index/console"]);
        if (!Users::updateByUid(session("user.uid"), ["token" => $openid])) return view("common/alert", ["msg" => "绑定失败", "url" => "/index/console"]);
        Users::updateMyInfo();
        return view("common/alert", ["msg" => "绑定成功", "url" => "/index/console"]);
    }

}

?>
