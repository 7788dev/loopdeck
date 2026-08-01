<?php
declare (strict_types=1);

namespace app\admin\controller;

use app\admin\model\Accounts;
use app\admin\model\Jobs;
use app\admin\model\Notice;
use app\admin\model\Order;
use app\admin\model\Tasks;
use app\admin\model\Weblist;
use app\admin\validate\Notices as NoticesValidate;
use app\admin\validate\Tasks as TasksValidate;
use app\admin\validate\Weblist as WeblistValidate;
use app\admin\validate\Users as UsersValidate;
use app\index\controller\Common;
use app\index\model\Kms;
use app\index\model\Users;
use app\service\SystemUpdater;
use mail\PHPMailer\PHPMailer;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Request;
use think\facade\Session;

class Ajax extends Common
{
    protected $middleware = [
        'app\middleware\CheckLoginUser',
        'app\middleware\CheckUserPower',
        'app\middleware\CheckAjaxRequest',
    ];

    public function update()
    {
        if (WEB_ID != 1) {
            return resultJson(0, '无权执行系统更新');
        }

        try {
            $result = (new SystemUpdater())->trigger();
            return resultJson(1, '更新任务已提交，正在拉取新镜像并重启服务', $result);
        } catch (\Throwable $exception) {
            return resultJson(0, '更新失败：' . $exception->getMessage());
        }
    }

    public function zipExtract($src, $dest)
    {
        return false;
    }

    public function set($act = null)
    {
        switch ($act) {
            case 'info':
                $data = Request::post();
                if (Weblist::updateByWebid(Session::get('user.web_id'), $data)) {
                    return resultJson(1, '信息修改成功');
                }
                break;
            case 'config':
                $data = Request::post();
                $web_data = Weblist::where('web_id', Session::get('user.web_id'))->find();
                $table = $web_data ? Weblist::configTableName($web_data['prefix']) : null;
                if ($table === null || empty($data)) {
                    return resultJson(0, '站点配置无效');
                }

                $records = [];
                foreach ($data as $key => $value) {
                    if (!is_string($key)
                        || preg_match('/\A[A-Za-z0-9_.-]{1,255}\z/', $key) !== 1
                        || (!is_scalar($value) && $value !== null)
                    ) {
                        return resultJson(0, '配置项格式无效');
                    }
                    $value = (string)$value;
                    if (strlen($value) > 65535) {
                        return resultJson(0, '配置内容过长');
                    }
                    if (in_array($key, ['mail_invalid', 'bark_enabled', 'is_netease_tool'], true)
                        && !in_array($value, ['0', '1'], true)
                    ) {
                        return resultJson(0, '开关配置只能为 0 或 1');
                    }
                    if ($key === 'netease_tool_limit'
                        && (preg_match('/\A[1-9]\d{0,3}\z/', $value) !== 1 || (int)$value > 1000)
                    ) {
                        return resultJson(0, '网易云工具每日次数必须在 1 到 1000 之间');
                    }
                    $records[] = [$key, $value];
                }

                try {
                    Db::transaction(static function () use ($table, $records): void {
                        foreach ($records as [$key, $value]) {
                            Db::execute(
                                "INSERT INTO `{$table}` (`k`, `v`) VALUES (:config_key, :config_value) "
                                . "ON DUPLICATE KEY UPDATE `v` = :updated_value",
                                [
                                    'config_key' => $key,
                                    'config_value' => $value,
                                    'updated_value' => $value,
                                ]
                            );
                        }
                    });
                } catch (\Throwable $exception) {
                    return resultJson(0, '配置保存失败');
                }
                return resultJson(1, '配置保存成功');
                break;
            case 'testSendMail':
                $host = trim((string)Request::post('mail_smtp', ''));
                $port = (int)Request::post('mail_port', 0);
                $username = trim((string)Request::post('mail_name', ''));
                $password = (string)Request::post('mail_pwd', '');
                $recipient = trim((string)Session::get('user.mail', ''));

                $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
                    || preg_match('/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/', $host) === 1;
                if (!$validHost || $port < 1 || $port > 65535 || !check_mail($username) || $password === '') {
                    return resultJson(0, 'SMTP 配置格式无效');
                }
                if (!check_mail($recipient)) {
                    return resultJson(0, '请先为当前管理员账号设置有效邮箱');
                }

                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = $host;
                    $mail->SMTPAuth = true;
                    $mail->Username = $username;
                    $mail->Password = $password;
                    $mail->Port = $port;
                    $mail->SMTPSecure = $port === 465
                        ? PHPMailer::ENCRYPTION_SMTPS
                        : PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->CharSet = 'UTF-8';
                    $mail->setFrom($username, (string)config('web.webname', 'LoopDeck'));
                    $mail->addAddress($recipient);
                    $mail->isHTML(true);
                    $mail->Subject = (string)config('web.webname', 'LoopDeck') . ' - 测试邮件';
                    $mail->Body = '<p>SMTP 信息推送配置测试成功。</p>';
                    $mail->send();
                    return resultJson(1, '测试邮件发送成功，请检查管理员邮箱');
                } catch (\Throwable $exception) {
                    return resultJson(0, '测试邮件发送失败，请检查 SMTP 配置');
                }
        }
    }

    public function pay($act = null)
    {
        switch ($act) {
            case 'order':
                return Order::getOrderList();
                break;
        }
    }

    public function task($act = null)
    {
        switch ($act) {
            case 'add':
                $task = new Tasks();
                $data = Request::post();
                try {
                    validate(TasksValidate::class)->scene('add')->check($data);
                } catch (ValidateException $e) {
                    //验证失败 输出错误信息
                    return resultJson(-1, $e->getMessage());
                }
                if ($task->addTask($data)) {
                    return resultJson(1, '添加成功');
                } else {
                    return resultJson(0, '添加失败');
                }
                break;
            case 'list':
                $task = new Tasks();
                return $task->getAllTask();
                break;
            case 'getInfo':
                $task = new Tasks();
                $data = Request::post();
                return $task->getById($data['id']);
                break;
            case 'set':
                switch ($act) {
                    default:
                        $task = new Tasks();
                        $jobs = new Jobs();
                        $data = Request::post();
                        $oTask = $task->where('id', '=', $data['id'])->find();
                        $up_task = $task->where('id', '=', $data['id'])->update($data);
                        if ($up_task == 0) {  // 无修改
                            return resultJson(1, '保存成功');
                        } else {
                            $job = $jobs->where('do', '=', $oTask['execute_name'])->select();
                            foreach ($job as $key => $value) {
                                $user = Users::findByUid($value['uid']);
                                $data['vip'] == 1 && empty($user['vip_start']) ? $state = 0 : $state = 1;
                                $jobs->where('do', '=', $oTask['execute_name'])->update([
                                    'type' => $data['type'],
                                    'do' => $oTask['execute_name'],
                                    'state' => $state,
                                ]);
                            }
                            return resultJson(1, '保存成功');
                        }
                        break;
                }
                break;
            case 'delete':
                $task = new Tasks();
                $id = Request::post('id');
                if ($task->where('id', '=', $id)->delete()) {
                    return resultJson(1, '删除任务成功');
                } else {
                    return resultJson(0, '删除任务失败');
                }
                break;
        }
    }

    public function data($act = null, $do = null)
    {
        switch ($act) {
            case 'list':
                switch ($do) {
                    case 'users':
                        return Users::getUserList();
                        break;
                    case 'kms':
                        return Kms::getKmList();
                        break;
                    case 'notices':
                        return Notice::getNoticeList();
                        break;
                    case 'accounts':
                        return Accounts::getAccountList();
                        break;
                    case 'sites':
                        return Weblist::getSitesList();
                        break;
                }
                break;
            case 'add':
                switch ($do) {
                    case 'km':
                        $data = Request::post();
                        switch ($data['type']) {
                            case 'vip':
                            case 'quota':
                            case 'agent':
                                //自动验证
                                try {
                                    validate(\app\index\validate\Kms::class)->scene('add')->check($data);
                                } catch (ValidateException $e) {
                                    //验证失败 输出错误信息
                                    return resultJson(-1, $e->getMessage());
                                }
                                return Kms::admin_add($data);
                                break;
                        }
                        break;
                    case 'notice':
                        $data = Request::post();
                        if ($data['type'] == 2 && WEB_ID != 1) { // 非主站无法添加后台公告
                            return resultJson(0, '添加失败');
                        }
                        //自动验证
                        try {
                            validate(NoticesValidate::class)->scene('add')->check($data);
                        } catch (ValidateException $e) {
                            //验证失败 输出错误信息
                            return resultJson(-1, $e->getMessage());
                        }
                        return Notice::add($data);
                        break;
                    case 'site':
                        $data = Request::post();
                        try {
                            validate(WeblistValidate::class)->scene('add')->check($data);
                        } catch (ValidateException $e) {
                            //验证失败 输出错误信息
                            return resultJson(-1, $e->getMessage());
                        }
                        return Weblist::add($data);
                        break;
                }
                break;
            case 'delete':
                switch ($do) {
                    case 'user':
                        $id = Request::post('id');
                        if ($id == 1) {
                            return resultJson(0, '不能删除管理员');
                        }
                        if (Users::delByUid($id)) {
                            return resultJson(1, '删除成功');
                        } else {
                            return resultJson(0, '删除失败');
                        }
                        break;
                    case 'km':
                        $id = Request::post('id');
                        if (Kms::delByid($id)) {
                            return resultJson(1, '删除成功');
                        } else {
                            return resultJson(0, '删除失败');
                        }
                        break;
                    case 'usedkm':
                        if (Kms::AdminDelUse()) {
                            return resultJson(1, '清空已使用卡密成功');
                        } else {
                            return resultJson(0, '没有可清空的卡密');
                        }
                        break;
                    case 'notice':
                        $id = Request::post('id');
                        if (Notice::delByid($id)) {
                            return resultJson(1, '删除成功');
                        } else {
                            return resultJson(0, '删除失败');
                        }
                        break;
                    case 'site':
                        $id = Request::post('id');
                        $web_data = Weblist::findByWebid($id);
                        $table = $web_data ? Weblist::configTableName($web_data['prefix']) : null;
                        if ($table === null) {
                            return resultJson(0, '站点数据无效');
                        }
                        $sql = "DROP TABLE IF EXISTS `{$table}`";
                        Db::execute($sql);  // 删除分站configs表
                        if (Weblist::delByid($id)) {
                            return resultJson(1, '删除成功');
                        } else {
                            return resultJson(0, '删除失败');
                        }
                        break;
                    case 'account':
                        $id = Request::post('id');
                        Accounts::delByid($id);
                        Jobs::delByid($id);
                        return resultJson(1, '删除成功');
                        break;
                }
                break;
            case 'set':
                switch ($do) {
                    case 'user':
                        $data = Request::post();
                        if (!empty($data['password'])) {
                            try {
                                validate(UsersValidate::class)->scene('edit')->check($data);
                            } catch (ValidateException $e) {
                                //验证失败 输出错误信息
                                return resultJson(-1, $e->getMessage());
                            }
                           $up['password'] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
                        }
                        if ($data['vip_start'] == '') {
                            $up['vip_start'] = NULL;
                        } else {
                            $up['vip_start'] = $data['vip_start'];
                        }
                        if ($data['vip_end'] == '') {
                            $up['vip_end'] = NULL;
                        } else {
                            $up['vip_end'] = $data['vip_end'];
                        }
                        if (!empty($data['agent'])) {
                            $up['agent'] = $data['agent'];
                        } else {
                            $up['agent'] = 0;
                        }
                        $up['money'] = $data['money'];
                        $up['quota'] = $data['quota'];
                        $up['state'] = $data['state'];
                        $up['qq'] = $data['qq'];
                        $up['mail'] = $data['mail'];
                        if (Users::updateByUid($data['id'], $up)) {
                            return resultJson(1, '编辑用户成功');
                        } else {
                            return resultJson(0, '编辑失败，无修改');
                        }
                        break;
                    case 'notice':
                        $id = Request::post('id');
                        $data = Request::post();
                        if (Notice::updateByid($id, $data)) {
                            return resultJson(1, '修改成功');
                        }
                        break;
                    case 'site':
                        $id = Request::post('web_id');
                        if ($id == 1) {
                            return resultJson(0, '无法操作');
                        }
                        $data = Request::post();
                        if (Weblist::updateByWebid($id, $data)) {
                            return resultJson(1, '修改成功');
                        }
                        break;
                }
                break;
            case 'info':
                switch ($do) {
                    case 'user':
                        $users = new Users();
                        $data = Request::post();
                        return $users->findByUid($data['id']);
                        break;
                    case 'notice':
                        $notices = new Notice();
                        $data = Request::post();
                        return $notices->findById($data['id']);
                        break;
                    case 'site':
                        $weblist = new Weblist();
                        $data = Request::post();
                        return $weblist->findByWebid($data['id']);
                        break;
                }
                break;
        }
    }

}
