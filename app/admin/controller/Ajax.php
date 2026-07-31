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

    public static function update()
    {
        return resultJson(
            -1,
            '在线更新已禁用。请审核更新内容后通过本地部署流程更新。'
        );
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
                foreach ($data as $k => $value) {
                    $web_data = Weblist::where('web_id', Session::get('user.web_id'))->find();
                    $res = Db::execute("INSERT INTO " . $web_data['prefix'] . "configs SET k='" . $k . "',v='" . $value . "' ON DUPLICATE KEY UPDATE v='" . $value . "'");
                }
                return resultJson(1, '配置保存成功');
                break;
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
                        $sql = "DROP TABLE IF EXISTS `" . $web_data['prefix'] . "configs`";
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
