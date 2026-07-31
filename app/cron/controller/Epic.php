<?php
declare(strict_types=1);

namespace app\cron\controller;

use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\Users;
use app\index\model\Weblist;
use think\facade\Request;

class Epic extends Common
{
    public function index()
    {
        $cronkey = Request::get('cronkey');
        if (empty($cronkey) || $cronkey != config('sys.cronkey')) {
            $res = ['code' => -1000, 'message' => 'CronKey Access Denied!'];
            exit(json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        $jobs = Jobs::where('type', '=', 'epic')
                    ->where('do', '=', 'weeklyGameNotify')
                    ->where('state', '=', 1)
                    ->whereTime('nextExecute', '<', time())
                    ->select();
        if (count($jobs) == 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        $urls = [];
        $vip_expired_userIds = [];
        foreach ($jobs as $job) {
            if (in_array($job['user_id'], $vip_expired_userIds)) continue;
            $user = Users::where('uid', '=', $job['uid'])->find();
            $timing = isset($job['data']) ? unserialize($job['data'])['timing'] : null;
            if ($timing == '' || empty($timing)) {
                $job->where('state', '=', 1)->update(['state' => 0]);
                continue;
            }
            if (strtotime($user['vip_end'] ?? '') < time()) {  // 判断会员功能、用户会员是否过期
                $this->vipExpired('epic', $user['uid'], $job['user_id']); // 会员过期处理
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $job['user_id'];
                continue;
            } else {
                $urls[] = get_Domain() . 'cron/epic/notify?user_id=' . $job['user_id'] . '&zid=' . $job['zid'] . '&runkey=' . RUN_KEY;
            }
            Info::where('sysid','=','100')->inc('times',1)->update();
            Info::where('sysid','=','100')->update(['last' => date('Y-m-d H:i:s')]);
            $week = date('w');
            $friday_stmp = strtotime('Friday');
            if ($week == 5 || $week == 6 || $week == 0) {
                // 如果在567 加到下一周通知
                $nextExecute = $friday_stmp + 604800;
            } else {
                // 不在567 本周五通知
                $nextExecute = $friday_stmp;
            }
            $nextExecute = isset($timing) ? strtotime($timing, $nextExecute) : $nextExecute + 600; // 加上挂机时间 没设置00：10通知
            Jobs::updateJobInfo($job['do'], $job['user_id'], [ // 更新任务执行信息
                'lastExecute' => date("Y-m-d H:i:s"),
                'nextExecute' => $nextExecute,
            ]);
        }
        if ($urls) $this->curl_mulit($urls);
        return resultJson(1000, '执行任务成功');
    }

    public function notify()
    {
        $data = Request::get();
        if (isset($data['runkey']) && $data['runkey'] == RUN_KEY) {
            $this->send_mail($data['user_id'], 'Epic游戏商城周免领取通知', $this->get_email_template($data['zid']), $data['zid']);
        } else {
            return resultJson(-1001, 'RunKey Access Denied!');
        }
    }

    private function get_email_template($zid)
    {
        $web  = Weblist::where('web_id', '=', $zid)->find();
        $obj = new \epic\Epic();
        $_html = '';
        foreach ($obj->getWeeklyFreeGames() as $weeklyFreeGame) {
            $_html .= '<tr>
                                <td style="text-align: center; padding: 30px 30px 0;">
                                    <img style="height: 300px;" src="'. $weeklyFreeGame['image'] .'" alt="image">
                                </td>
                            </tr>
                            <tr>
                                <td style="text-align:center;padding: 15px 30px 30px 30px;">
                                    <h2 style="font-size: 24px; color: #6576ff; font-weight: 600; margin-bottom: 8px;">'. $weeklyFreeGame['title'] .'</h2>
                                    <p style="margin-bottom: 16px;">'. $weeklyFreeGame['description'] .'</p>
                                    <a href="'. $weeklyFreeGame['productUrl'] .'" style="background-color:#6576ff;border-radius:4px;color:#ffffff;display:inline-block;font-size:13px;font-weight:600;line-height:38px;text-align:center;text-decoration:none;text-transform: uppercase; padding: 0 30px">点我领取</a>
                                </td>
                            </tr>';
        }
        $html = "<!DOCTYPE html>
<html lang=\"en\" xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:v=\"urn:schemas-microsoft-com:vml\" xmlns:o=\"urn:schemas-microsoft-com:office:office\">
<head>
    <meta charset=\"utf-8\">
    <meta name=\"viewport\" content=\"width=device-width\">
    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">
    <meta name=\"x-apple-disable-message-reformatting\">
    <title></title>

    <link href=\"https://fonts.googleapis.com/css?family=Roboto:400,600\" rel=\"stylesheet\" type=\"text/css\">
    <!-- Web Font / @font-face : BEGIN -->
    <!--[if mso]>
        <style>
            * {
        font-family: 'Roboto', sans-serif !important;
            }
        </style>
    <![endif]-->

    <!--[if !mso]>
        <link href=\"https://fonts.googleapis.com/css?family=Roboto:400,600\" rel=\"stylesheet\" type=\"text/css\">
    <![endif]-->

    <!-- Web Font / @font-face : END -->

    <!-- CSS Reset : BEGIN -->


    <style>
    /* What it does: Remove spaces around the email design added by some email clients. */
    /* Beware: It can remove the padding / margin and add a background color to the compose a reply window. */
    html,
        body {
        margin: 0 auto !important;
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
            font-family: 'Roboto', sans-serif !important;
            font-size: 13px;
            margin-bottom: 10px;
            line-height: 24px;
            color:#8094ae;
            font-weight: 400;
        }
        * {
        -ms-text-size-adjust: 100%;
            -webkit-text-size-adjust: 100%;
            margin: 0;
            padding: 0;
        }
        table,
        td {
        mso-table-lspace: 0pt !important;
            mso-table-rspace: 0pt !important;
        }
        table {
        border-spacing: 0 !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            margin: 0 auto !important;
        }
        table table table {
        table-layout: auto;
        }
        a {
        text-decoration: none;
        }
        img {
        -ms-interpolation-mode:bicubic;
        }
    </style>

</head>

<body width=\"100%\" style=\"margin: 0; padding: 0 !important; mso-line-height-rule: exactly; background-color: #f5f6fa;\">
	<center style=\"width: 100%; background-color: #f5f6fa;\">
        <table width=\"100%\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" bgcolor=\"#f5f6fa\">
            <tr>
               <td style=\"padding: 40px 0;\">
                    <table style=\"width:100%;max-width:620px;margin:0 auto;\">
                        <tbody>
                            <tr>
                                <td style=\"text-align: center; padding-bottom:25px\">
                                    <a href=\"".request()->scheme()."://".$web['domain']."\">{$web['webname']}</a>
                                    <p style=\"font-size: 20px; color: #6576ff; padding-top: 12px;\">Epic游戏商城周免领取通知</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <table style=\"width:100%;max-width:620px;margin:0 auto; color:#9ea8bb;\" cellpadding=\"0\" cellspacing=\"0\" bgcolor=\"#ffffff\" >
                        <tbody>
                        ".$_html."
                        </tbody>
                    </table>
                    <table style=\"width:100%;max-width:620px;margin:0 auto;\">
                        <tbody>
                            <tr>
                                <td style=\"text-align: center; padding:25px 20px 0;\">
                                    <p style=\"font-size: 13px;\">{$web['webname']}. All rights reserved.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
               </td>
            </tr>
        </table>
    </center>
</body>
</html>";
        return $html;
    }
}