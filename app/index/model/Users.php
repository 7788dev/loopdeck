<?php
declare (strict_types=1);

namespace app\index\model;

use app\index\validate\Users as UsersValidate;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Session;
use think\Model;
use think\response\Json;


class Users extends Model
{
    protected $pk = 'uid';

    /**
     * reg 用户注册
     * @param $data
     * @return Json|void
     * @author BadCen
     */
    public static function reg($data)
    {
        if (config('sys.reg_close') == 1) {
            return resultJson(-1, '已关闭注册');
        }
        //自动验证
        try {
            validate(UsersValidate::class)->scene('reg')->check($data);
        } catch (ValidateException $e) {
            //验证失败 输出错误信息
            return resultJson(-1, $e->getMessage());
        }
        $self = new static();
        if (config('sys.reg_iplimit') == 1) {
            if ($self->where('login_ip', real_ip())->find()) {
                return resultJson(-1, 'IP已经注册过账号');
            }
        }
        if ($self->where('username', $data['username'])->find()) {
            return resultJson(-1, '用户名已存在');
        } elseif ($self->where('qq', $data['qq'])->find()) {
            return resultJson(-1, '该QQ已存在');
        } else {
            if (config('sys.reg_free_vip') == 1) {
                $vip_start = date('Y-m-d H:i:s');
                $vip_end = date('Y-m-d', strtotime('+' . config('sys.reg_free_vip_day') . 'day'));
            }
            if (config('sys.reg_free_quota') == 1) {
                $quota = config('sys.reg_free_quota_num');
            }
            if (config('sys.reg_free_money') == 1) {
                $money = config('sys.reg_free_money_num');
            }
            if (config('sys.reg_free_agent') == 1) {
                $give = config('sys.reg_free_agent_grand');
                switch ($give) {
                    case 1:
                        $agent = 1;
                        break;
                    case 2:
                        $agent = 2;
                        break;
                    case 3:
                        $agent = 3;
                        break;
                }
            } else {
                $agent = 0;
            }
            $ret = $self->create([
                'web_id' => WEB_ID,
                'username' => $data['username'],
                'password' => md5($data['password']),
                'qq' => $data['qq'],
                'mail' => $data['qq'] . '@qq.com',
                'nickname' => get_qqname($data['qq']),
                'money' => $money ?? 0.00,
                'quota' => $quota ?? 0,
                'vip_start' => $vip_start ?? null,
                'vip_end' => $vip_end ?? null,
                'agent' => $agent ?? 0,
                'login_time' => time(),
                'login_ip' => real_ip(),
                'login_city' => get_ip_city(real_ip()),
            ]);
            if ($ret->uid) {
                return resultJson(1, '注册成功');
            } else {
                return resultJson(0, '注册失败');
            }
        }
    }

    /**
     * findPass 找回密码
     * @param $data
     * @return Json|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function findPass($data)
    {
        //自动验证
        try {
            validate(UsersValidate::class)->scene('find')->check($data);
        } catch (ValidateException $e) {
            //验证失败 输出错误信息
            return resultJson(-1, $e->getMessage());
        }
        $self = new static();
        $row = $self->where('mail', $data['mail'])->find();
        if (!$row) {
            return resultJson(-1, '邮箱不存在');
        } else {
            $user_data = $self->where('mail', '=', $data['mail'])->find();
            $token = md5($user_data['uid'] . $user_data['username'] . $user_data['password'] . time() . real_ip());
            Users::updateByUid($user_data['uid'], [
                'sid' => $token
            ]);
            $sign = get_Domain() . 'index/login/reset/?mail=' . $data['mail'] . '&token=' . $token . '&access=' . get_os();
            $content = get_mail_tempale(2, $user_data, $sign);
            if ($result = Captcha::send_captcha($data['mail'], '找回密码', $content)) {
                Captcha::add([
                        'type' => '2',
                        'code' => $token,
                        'send' => $data['mail']
                    ]
                );
                return resultJson($result['code'], $result['message']);
            }
        }
    }

    /**
     * updateByUid 更新用户信息
     * @param $uid
     * @param $data
     * @author BadCen
     */
    public static function updateByUid($uid, $data = [])
    {
        $self = new static();
        if ($result = $self->where('uid', '=', $uid)->update($data)) {
            return $result;
        }
        return false;
    }

    /**
     * reset 重置密码
     * @param $data
     * @return Json|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function reset($data)
    {
        //自动验证
        try {
            validate(UsersValidate::class)->scene('reset')->check($data);
        } catch (ValidateException $e) {
            //验证失败 输出错误信息
            return resultJson(-1, $e->getMessage());
        }
        $self = new static();
        $captcha = new Captcha();
        $row = $self->where('mail', '=', $data['mail'])->find();
        $captcha = $captcha->where('type', '=', '2')->where('send', '=', $data['mail'])->order('id', 'desc')->find();
        $token = $captcha['code'];
        if (!$row) {
            return resultJson(-1, '邮箱不存在');
        } elseif ($data['token'] != $token || !isset($token) || !isset($data['token'])) {
            return resultJson(-1, 'Token错误');
        } else {
            $new_pass = md5($data['repass']);
            if (Users::where('uid', '=', $row['uid'])->update([
                'password' => $new_pass
            ])) {
                $login = [
                    'username' => $row['username'],
                    'password' => $data['password']
                ];
                Users::login($login);
                return resultJson(1, '重置密码成功，登录中');
            } else {
                return resultJson(0, '重置密码失败，请重新操作！');
            }
        }
    }

    /**
     * login 用户登录
     * @param $data
     * @return Json
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function login($data)
    {
        //自动验证
        try {
            validate(UsersValidate::class)->scene('login')->check($data);
        } catch (ValidateException $e) {
            //验证失败 输出错误信息
            return resultJson(-1, $e->getError());
        }
        $self = new static();
        $row = $self->where('username', $data['username'])->find();
        if (!$row) {
            return resultJson(-1, '用户名不存在');
        } elseif ($row['state'] !== 1) {
            return resultJson(-1, '该账号已被封禁');
        } elseif (md5($data['password']) !== $row['password']) {
            return resultJson(-1, '密码错误');
        } elseif ($row['web_id'] !== WEB_ID) {
            $site = Weblist::where('web_id', '=', $row['web_id'])->find();
            return resultJson(-1001, '该账号不属于当前站点，正在跳转到' . $site['domain'] . '进行登录', ['url' => $site['domain']]);
        } else {
            $sign = md5($row['uid'] . $row['username'] . $row['password'] . time() . real_ip());
            $self->where('uid', $row['uid'])
                ->update([
                    'sid' => $sign,
                    'login_ip' => real_ip(),
                    'login_city' => get_ip_city(real_ip()),
                    'login_time' => time()
                ]);
            $session_data = $self->where('uid', $row['uid'])->withoutField('password')->find();
            Session::set('user', $session_data->toArray());
            return resultJson(1, '登录成功');
        }
    }

    /**
     * changePassWord 修改密码
     * @param $uid
     * @param $data
     * @return Json
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function changePassWord($uid, $data)
    {
        //自动验证
        try {
            validate(UsersValidate::class)->scene('changePassWord')->check($data);
        } catch (ValidateException $e) {
            //验证失败 输出错误信息
            return resultJson(-1, $e->getError());
        }
        $self = new static();
        $row = $self->where('uid', '=', $uid)->find();
        if (md5($data['outpass']) != $row['password']) {
            return resultJson(-1, '原密码错误');
        } else {
            $newPass = md5($data['repass']);
            if (Users::where('uid', '=', Session::get('user.uid'))->update(['password' => $newPass])) {
                Session::delete('user');
                return resultJson(1, '修改成功，请重新登录');
            } else {
                return resultJson(0, '修改失败');
            }
        }
    }

    /**
     * updateMyInfo 更新用户的SESSION缓存
     * @author BadCen
     */
    public static function updateMyInfo()
    {
        $self = new static();
        $ret = $self->getByUid(Session::get('user.uid'));
        if ($ret) {
            session::set('user', $ret->toArray());
        }
    }

    /**
     * logout 注销登录
     * @return Json
     * @author BadCen
     */
    public static function logout()
    {
        Session::delete("user");
        return resultJson(1, '退出登录成功');
    }

    /**
     * userCount 用户数量
     * @return false|int
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function userCount()
    {
        $self = new static();
        return $self->where('web_id', '=', WEB_ID)->count('uid');
    }

    /**
     * agentCount 代理数量
     * @return false|int
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public
    static function agentCount()
    {
        $self = new static();
        return $self->where('agent', '<>', 0)->where('web_id', '=', WEB_ID)->select()->count('uid');
    }

    /**
     * findByUid
     * @param $uid
     * @return Users|array|false|Model|null
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @author BadCen
     */
    public static function findByUid($uid)
    {
        $self = new static();
        if ($result = $self->where('uid', $uid)->find()) {
            return $result;
        }
        return false;
    }

    public static function getUserList()
    {
        $start = (int)input('post.start');
        $length = (int)input('post.length');
        $search = input('post.search');

        $self = new static();
        $query = $self->alias('a');
        $query->withoutField('password,sid');

        $nickname = $search['nickname'];

        if (!empty($search['uid'])) $query->where('uid', '=',  $search['uid']);
        if (!empty($search['nickname'])) $query->where('nickname', 'like',  "%$nickname%");
        if (!empty($search['username'])) $query->where('username', '=',  $search['username']);
        if (!empty($search['qq'])) $query->where('qq', '=',  $search['qq']);
        if (is_numeric($search['status'])) $query->where('state', '=',  $search['status']);

        if (WEB_ID != 1) {
            $query->where('web_id', '=', WEB_ID);
        }

        if ($result = $query->order('a.uid desc')->limit($start, $length)->select()) {
            return [
                'total' => $query->count('uid'),
                'page' => input('post.page'),
                'data' => $result,
            ];
        }
        return false;
    }

    public static function delByUid($uid)
    {
        $self = new static();
        if ($result = $self->where('uid', '=', $uid)->delete()) {
            return $result;
        } else {
            return false;
        }
    }

    public static function delBySiteid($id)
    {
        $self = new static();
        if ($self->where('web_id', '=', $id)->delete()) {
            return true;
        }
        return false;
    }

}
