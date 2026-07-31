<?php

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\TaskLogs;
use app\index\model\Jobs;
use app\index\model\Users;
use app\index\model\Weblist;
use mail\PHPMailer\Exception;
use mail\PHPMailer\PHPMailer;
use think\facade\Config;
use think\facade\Db;
use think\facade\Request;

class Common
{
    public function vipExpired($type, $uid, $user_id)
    {
        Users::where('uid', '=', $uid)->update(['vip_start' => NULL, 'vip_end' => NULL]);
        Jobs::where('type', '=', $type)->where('user_id', '=', $user_id)->update(['state' => 0]);
        $zid = Jobs::where('type', '=', $type)->where('user_id', '=', $user_id)->value('zid');
        $data = [
            'type' => $type,
            'user_id' => $user_id,
            'do' => '系统提示',
            'response' => '会员过期，请开通会员后再试',
        ];
        TaskLogs::operateLog($data);
        if (config('sys.mail_invalid') == 1) {
            $user = Users::getByUid($uid);
            $msg = $this->get_mail_tempale(4, $user, null, $zid);
            $sub = '会员过期通知';
            $this->send_mail($user['mail'], $sub, $msg, $zid);
        }
    }

    public function accountInvalid($type, $user, $user_id)
    {
        $name = match ($type) {
            'netease' => '网易云音乐',
            'bilibili' => '哔哩哔哩',
            'iqiyi' => '爱奇艺',
            'qq' => 'QQ',
            'sport' => '小米运动',
            'tieba' => '百度贴吧',
            'mihoyo' => '米游社',
        };
        Accounts::where('user_id', '=', $user_id)->update(['state' => 0]);
        Jobs::where('user_id', '=', $user_id)
            ->where('state', '=', 1)
            ->where('type', '=', $type)
            ->update(['state' => -1]); // state -1 代表账号失效
        $zid = Jobs::where('type', '=', $type)->where('user_id', '=', $user_id)->value('zid');
        if (config('sys.mail_invalid') == 1) {
            $msg = $this->get_mail_tempale(3, $user, $name, $zid);
            $sub = '失效提醒';
            $this->send_mail($user['mail'], $sub, $msg, $zid);
        }
    }

    public function curl($url)
    {
        $curl = curl_init();
        $url_arr = parse_url($url);
        if (config('sys.local_cron') == 1 && $url_arr['host'] == $_SERVER['HTTP_HOST']) {
            $url = str_replace('http://' . $_SERVER['HTTP_HOST'] . '/', 'http://127.0.0.1:80/', $url);
            $url = str_replace('https://' . $_SERVER['HTTP_HOST'] . '/', 'https://127.0.0.1:443/', $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Host: ' . $_SERVER['HTTP_HOST']));
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 1);
        curl_setopt($curl, CURLOPT_NOBODY, 1);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_AUTOREFERER, 1);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.152 Safari/537.36');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        $ret = curl_exec($curl);
        curl_close($curl);
        return $ret;
    }

    public function curl_mulit($urls)
    {
        // 创建批处理cURL句柄
        $mh = curl_multi_init();
        foreach ($urls as $i => $url) {
            // 创建一对cURL资源
            $conn[$i] = curl_init();

            $url_arr = parse_url($url);
            if(config('sys.local_cron') == 1 && $url_arr['host'] == $_SERVER['HTTP_HOST']){
                $url=str_replace('http://'.$_SERVER['HTTP_HOST'].'/','http://127.0.0.1/',$url);
                $url=str_replace('https://'.$_SERVER['HTTP_HOST'].'/','https://127.0.0.1/',$url);
                curl_setopt($conn[$i], CURLOPT_HTTPHEADER, array('Host: '.$_SERVER['HTTP_HOST']));
            }
            // 设置URL和相应的选项
            //ua
            curl_setopt($conn[$i], CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/40.0.2214.93 Safari/537.36');
            //ssl验证
            curl_setopt($conn[$i], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($conn[$i], CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($conn[$i], CURLOPT_URL, $url);
            curl_setopt($conn[$i], CURLOPT_HEADER, 0);
            curl_setopt($conn[$i], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($conn[$i], CURLOPT_TIMEOUT, 10);
            //302跳转
            curl_setopt($conn[$i], CURLOPT_FOLLOWLOCATION, 1);
            // 增加句柄
            curl_multi_add_handle($mh, $conn[$i]);
        }
        $active = null;
        //防卡死写法：执行批处理句柄
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }
        foreach ($urls as $i => $url) {
            //获取当前解析的cURL的相关传输信息
            $info = curl_multi_info_read($mh);
            //获取请求头信息
            $heards = curl_getinfo($conn[$i]);
            //获取输出的文本流
            $res[$i] = curl_multi_getcontent($conn[$i]);
            // 移除curl批处理句柄资源中的某个句柄资源
            curl_multi_remove_handle($mh, $conn[$i]);
            //关闭cURL会话
            curl_close($conn[$i]);
        }
        //关闭全部句柄
        curl_multi_close($mh);
        return $res;
    }

    public function send_mail($to, $sub, $msg, $zid)
    {
        $config = [];
        $web  = Weblist::where('web_id', '=', $zid)->find();
        $res = Db::table($web['prefix'] . 'configs')->select()->toArray();
        foreach ($res as $k => $v) {
            $config = array_merge($config, array($res[$k]['k'] => $res[$k]['v']));
        }
        $mail_configs = [
            'mail_smtp' => $config['mail_smtp'],
            'mail_name' => $config['mail_name'],
            'mail_pwd' => $config['mail_pwd'],
            'mail_port' => $config['mail_port'],
        ];
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->SMTPDebug = -1;                      //Enable verbose debug output
            $mail->isSMTP();                                            //Send using SMTP
            $mail->Host       = $mail_configs['mail_smtp'];                     //Set the SMTP server to send through
            $mail->SMTPAuth   = true;                                   //Enable SMTP authentication
            $mail->Username   = $mail_configs['mail_name'];                     //SMTP username
            $mail->Password   = $mail_configs['mail_pwd'];                               //SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            //Enable implicit TLS encryption
            $mail->Port       = $mail_configs['mail_port'];                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

            //Recipients
            $mail->setFrom($mail_configs['mail_name'], $mail_configs['mail_name']);
            $mail->addAddress($to, $web['webname']);     //Add a recipient

            //Content
            $mail->isHTML(true);                                  //Set email format to HTML
            $mail->Subject = $web['webname'] . ' - ' . $sub;
            $mail->Body    = $msg;

            $mail->send();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function get_mail_tempale($type, $user, $pamars, $zid)
    {
        $web  = Weblist::where('web_id', '=', $zid)->find();
        if ($type == 3) { // 状态失效
            return "<div id=\"cTMail-Wrap\" style=\"box-sizing:border-box;text-align:center;min-width:320px; max-width:660px; border:1px solid #f6f6f6; background-color:#f7f8fa; margin:auto; padding:20px 0 30px; font-family:&#39;helvetica neue&#39;,PingFangSC-Light,arial,&#39;hiragino sans gb&#39;,&#39;microsoft yahei ui&#39;,&#39;microsoft yahei&#39;,simsun,sans-serif\">
    <div class=\"main-content\" style=\"\">
        <table style=\"width:100%;font-weight:300;margin-bottom:10px;border-collapse:collapse\">
            <tbody>
            <tr style=\"font-weight:300\">
                <td style=\"width:3%;max-width:30px;\"></td>
                <td style=\"max-width:600px;\">
                    <p style=\"height:2px;background-color: #00a4ff;border: 0;font-size:0;padding:0;width:100%;margin-top:20px;\"></p>
                    <div id=\"cTMail-inner\" style=\"background-color:#fff; padding:23px 0 20px;box-shadow: 0px 1px 1px 0px rgba(122, 55, 55, 0.2);text-align:left;\">
                        <table style=\"width:100%;font-weight:300;margin-bottom:10px;border-collapse:collapse;text-align:left;\">
                            <tbody>
                            <tr style=\"font-weight:300\">
                                <td style=\"width:3.2%;max-width:30px;\"></td>
                                <td style=\"max-width:480px;text-align:left;\">
                                    <h1 id=\"cTMail-title\" style=\"font-weight:bold;font-size:20px; line-height:36px; margin:0 0 16px;\">" . $web['webname'] . " - 邮件提醒</h1>
                                    <p id=\"cTMail-userName\" style=\"font-size:14px;color:#333; line-height:24px; margin:0;\">尊敬的：" . $user['nickname'] . "，您好！</p>
                                    <p class=\"cTMail-content\" style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 24px; margin: 6px 0px 0px; word-wrap: break-word; word-break: break-all;\">这封信是由" . $web['webname'] . "（" . $web['domain'] . "）发送的。</p>
                                    <p class=\"cTMail-content\" style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 24px; margin: 6px 0px 0px; word-wrap: break-word; word-break: break-all;\">您在我们网站挂机的 ". $pamars ." 账号状态已失效，请及时更新，<a href=\"".request()->scheme()."://".$web['domain']."\" target=\"_blank\">点我前往更新</a></p>
                                    <p class=\"cTMail-content\" style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 24px; margin: 6px 0px 0px; word-wrap: break-word; word-break: break-all;\">失效时间：" . date('Y-m-d H:i:s') . ",</p>
                                   <br/>
                                    </p>
                                    <dl style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 18px;\">
                                        <dd style=\"margin: 0px 0px 6px; padding: 0px; font-size: 12px; line-height: 22px;\"><p id=\"cTMail-sender\" style=\"font-size: 14px; line-height: 26px; word-wrap: break-word; word-break: break-all; margin-top: 32px;\">此致 <br  />
                                            <strong>" . $web['webname'] . "</strong></p>
                                        </dd>
                                    </dl>
                                </td>
                                <td style=\"width:3.2%;max-width:30px;\"></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
</div>";
        } elseif ($type == 4) { // 会员过期
            return "<div id=\"cTMail-Wrap\" style=\"box-sizing:border-box;text-align:center;min-width:320px; max-width:660px; border:1px solid #f6f6f6; background-color:#f7f8fa; margin:auto; padding:20px 0 30px; font-family:&#39;helvetica neue&#39;,PingFangSC-Light,arial,&#39;hiragino sans gb&#39;,&#39;microsoft yahei ui&#39;,&#39;microsoft yahei&#39;,simsun,sans-serif\">
    <div class=\"main-content\" style=\"\">
        <table style=\"width:100%;font-weight:300;margin-bottom:10px;border-collapse:collapse\">
            <tbody>
            <tr style=\"font-weight:300\">
                <td style=\"width:3%;max-width:30px;\"></td>
                <td style=\"max-width:600px;\">
                    <p style=\"height:2px;background-color: #00a4ff;border: 0;font-size:0;padding:0;width:100%;margin-top:20px;\"></p>
                    <div id=\"cTMail-inner\" style=\"background-color:#fff; padding:23px 0 20px;box-shadow: 0px 1px 1px 0px rgba(122, 55, 55, 0.2);text-align:left;\">
                        <table style=\"width:100%;font-weight:300;margin-bottom:10px;border-collapse:collapse;text-align:left;\">
                            <tbody>
                            <tr style=\"font-weight:300\">
                                <td style=\"width:3.2%;max-width:30px;\"></td>
                                <td style=\"max-width:480px;text-align:left;\">
                                    <h1 id=\"cTMail-title\" style=\"font-weight:bold;font-size:20px; line-height:36px; margin:0 0 16px;\">" . $web['webname'] . " - 邮件提醒</h1>
                                    <p id=\"cTMail-userName\" style=\"font-size:14px;color:#333; line-height:24px; margin:0;\">尊敬的：" . $user['nickname'] . "，您好！</p>
                                    <p class=\"cTMail-content\" style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 24px; margin: 6px 0px 0px; word-wrap: break-word; word-break: break-all;\">这封信是由" . $web['webname'] . "（" . $web['domain'] . "）发送的。</p>
                                    <p class=\"cTMail-content\" style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 24px; margin: 6px 0px 0px; word-wrap: break-word; word-break: break-all;\">您在我们网站开通的会员已经过期，部分功能可能无法正常运行。<a href=\"".request()->scheme()."://".$web['domain']."\" target=\"_blank\">点我前往续费</a></p>
                                    <p class=\"cTMail-content\" style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 24px; margin: 6px 0px 0px; word-wrap: break-word; word-break: break-all;\">过期时间：" . date('Y-m-d H:i:s') . ",</p>
                                   <br/>
                                    </p>
                                    <dl style=\"font-size: 14px; color: rgb(51, 51, 51); line-height: 18px;\">
                                        <dd style=\"margin: 0px 0px 6px; padding: 0px; font-size: 12px; line-height: 22px;\"><p id=\"cTMail-sender\" style=\"font-size: 14px; line-height: 26px; word-wrap: break-word; word-break: break-all; margin-top: 32px;\">此致 <br  />
                                            <strong>" . $web['webname'] . "</strong></p>
                                        </dd>
                                    </dl>
                                </td>
                                <td style=\"width:3.2%;max-width:30px;\"></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
</div>";
        }
    }
}