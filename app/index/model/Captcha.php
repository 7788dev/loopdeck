<?php
declare (strict_types=1);

namespace app\index\model;

use think\Model;

class Captcha extends Model
{

    public static function send_captcha($number, $title, $content)
    {
        $self = new static();
        $send_code = rand(111111, 999999);
        if (check_mail($number)) {//邮箱
            $row = $self->where('send', '=', $number)->order('id', 'desc')->field('time')->find();
            if ($row) {
                if ($row['time'] > time() - 60) {
                    return ['code' => -1, 'message' => '发送邮件之间需要相隔60秒'];
                }
                $where['send'] = $number;
                $where['time'] = ['>', time() - 3600 * 24];
                $count = $self->where($where)->count();
                if ($count > 6) {
                    return ['code' => -1, 'message' => '该邮箱发送次数过多，请更换邮箱'];
                }
                $where['ip'] = real_ip();
                $where['time'] = ['>', time() - 3600 * 24];
                $count = $self->where($where)->count();
                if ($count > 10) {
                    return ['code' => -1, 'message' => '该邮箱今日发送次数过多，请更换邮箱'];
                 }
                }
                $sub = config('web.webname') . ' - ' . $title;
                if ($content) {
                    $msg = $content;
                } else {
                    $msg = get_mail_tempale(1, $title, $send_code);
                }
                $send_result = send_mail($number, $sub, $msg);
                if ($send_result) {
                    Captcha::add([
                            'type' => '1',
                            'code' => $send_code,
                            'send' => $number
                        ]
                    );
                    return ['code' => 1, 'message' => '验证码已成功发送至您的邮箱，请注意查收'];
                } else {
                    return ['code' => 0, 'message' => '发送邮件失败，请联系管理员'];
                }
        } else {
            return ['code' => 1, 'message' => '邮箱格式不合法'];
        }
    }

    public static function add($data)
    {
        $self = new static();
        return $self->insert([
            'type' => $data['type'],
            'code' => $data['code'],
            'send' => $data['send'],
            'time' => time(),
            'ip' => real_ip(),
            'status' => 0,
        ]);
    }

    public static function check_captcha($number, $code)
    {
        $self = new static();
        $where['type'] = 1;
        $where['send'] = $number;
        $where['code'] = $code;
        $row = $self->where($where)->order('id', 'desc')->find();
        if (!$row) {
            return resultJson(1, '验证码不正确！');
        } elseif ($row['time'] < time() - 300 || $row['status'] > 0) {
            return resultJson(1, '验证码已失效，请重新获取！');
        }
    }


}