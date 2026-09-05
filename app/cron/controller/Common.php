<?php

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\TaskLogs;
use app\index\model\Jobs;
use app\index\model\Users;
use app\index\model\Weblist;
use app\service\NotificationService;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use think\facade\Config;
use think\facade\Db;
use think\facade\Request;

class Common
{
    /**
     * Short status tag rendered as a badge in the log list. Accepts every
     * result shape the cron controllers produce: raw adapter results
     * (`code` 200 / 1, optional `data.retry_after_seconds`), normalized
     * scheduler results (`success` bool, optional `retry_after_seconds`)
     * and anything non-array, which counts as failed.
     *
     * @param mixed $result
     */
    public function statusTag(mixed $result): string
    {
        if (!is_array($result)) {
            return '失败';
        }
        $success = array_key_exists('success', $result)
            ? !empty($result['success'])
            : in_array((int)($result['code'] ?? 0), [1, 200], true);
        if ($success) {
            return '成功';
        }
        $retryAfter = (int)($result['data']['retry_after_seconds']
            ?? $result['retry_after_seconds']
            ?? 0);

        return $retryAfter > 0 ? '重试中' : '失败';
    }

    public function vipExpired($type, $uid, $user_id)
    {
        $membershipChanged = Users::where('uid', '=', $uid)
            ->whereRaw('(`vip_start` IS NOT NULL OR `vip_end` IS NOT NULL)')
            ->update(['vip_start' => NULL, 'vip_end' => NULL]);
        Jobs::where('type', '=', $type)
            ->where('uid', '=', $uid)
            ->where('user_id', '=', $user_id)
            ->update(['state' => 0, 'nextExecute' => 0]);
        $data = [
            'type' => $type,
            'user_id' => $user_id,
            'do' => '系统提示',
            'response' => '[失败] 会员过期，请开通会员后再试',
        ];
        TaskLogs::operateLog($data);
        $user = $membershipChanged > 0 ? Users::getByUid($uid) : null;
        if ($membershipChanged > 0 && $user) {
            (new NotificationService())->sendVipExpired($user);
        }
    }

    public function accountInvalid($type, $user, $user_id)
    {
        $name = match ($type) {
            'netease' => '网易云音乐',
            'bilibili' => '哔哩哔哩',
            'qq' => 'QQ',
            'sport' => '小米运动',
            'heybox' => '小黑盒',
        };
        $stateChanged = Accounts::where('user_id', '=', $user_id)
            ->where('type', '=', $type)
            ->where('uid', '=', $user['uid'])
            ->where('state', '=', 1)
            ->update(['state' => 0]);
        Jobs::where('user_id', '=', $user_id)
            ->where('uid', '=', $user['uid'])
            ->where('state', '=', 1)
            ->where('type', '=', $type)
            ->update(['state' => -1]); // state -1 代表账号失效
        if ($stateChanged > 0) {
            (new NotificationService())->sendAccountInvalid($user, $name, (string)$user_id);
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
        // A one second cap could not even cover the connect timeout above, so
        // scheduler calls were being killed before the worker had answered.
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_NOBODY, 1);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_AUTOREFERER, 1);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.152 Safari/537.36');
        $this->applyTlsOptions($curl, $url);
        $ret = curl_exec($curl);
        curl_close($curl);
        return $ret;
    }

    /**
     * Verify TLS for every remote hop. Loopback self-calls keep verification
     * off because a local deployment normally terminates TLS with a
     * self-signed certificate.
     *
     * @param \CurlHandle $handle
     */
    protected function applyTlsOptions($handle, string $url): void
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, !$loopback);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $loopback ? 0 : 2);
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
            $this->applyTlsOptions($conn[$i], $url);
            curl_setopt($conn[$i], CURLOPT_URL, $url);
            curl_setopt($conn[$i], CURLOPT_HEADER, 0);
            curl_setopt($conn[$i], CURLOPT_RETURNTRANSFER, 1);
            // Long-running task workers must not have the connection dropped
            // mid-run; PHP aborts the worker script when the client goes away.
            curl_setopt($conn[$i], CURLOPT_TIMEOUT, 300);
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
            $mail->SMTPSecure = (int)$mail_configs['mail_port'] === 465
                ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 8;
            $mail->getSMTPInstance()->Timelimit = 8;
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
