<?php
declare(strict_types=1);

namespace app\cron\controller;

use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\TaskLogs;
use app\index\model\Users;
use app\index\model\Weblist;
use think\facade\Request;
use Throwable;

class Epic extends Common
{
    public function index()
    {
        $cronkey = (string)Request::get('cronkey', '');
        $expected = (string)config('sys.cronkey');
        if ($cronkey === '' || $expected === '' || !hash_equals($expected, $cronkey)) {
            $res = ['code' => -1000, 'message' => 'CronKey Access Denied!'];
            exit(json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        $jobs = Jobs::where('type', '=', 'epic')
                    ->where('do', '=', 'weeklyGameNotify')
                    ->where('state', '=', 1)
                    ->where('nextExecute', '<', time())
                    ->select();
        if (count($jobs) == 0) {
            return resultJson(-1002, '没有要执行的任务');
        }
        $vip_expired_userIds = [];
        foreach ($jobs as $job) {
            if (in_array($job['user_id'], $vip_expired_userIds)) continue;
            if (!Jobs::claimDueJob((int)$job['id'], (int)$job['nextExecute'])) continue;
            $user = Users::where('uid', '=', $job['uid'])->find();
            $timing = isset($job['data']) ? (safe_unserialize_array($job['data'])['timing'] ?? null) : null;
            if ($timing == '' || empty($timing)) {
                // 按主键停用本条任务；$job->where(...) 会丢掉主键条件变成全表 UPDATE
                Jobs::where('id', (int)$job['id'])->update(['state' => 0]);
                continue;
            }
            if (strtotime($user['vip_end'] ?? '') < time()) {  // 判断会员功能、用户会员是否过期
                $this->vipExpired('epic', $user['uid'], $job['user_id']); // 会员过期处理
                // 将VIP过期的任务用户id放入一个数组，用于后续判断
                $vip_expired_userIds[] = $job['user_id'];
                continue;
            } else {
                // 收件人来自订单/任务行（邮箱在 jobs.user_id），不再由 URL 参数决定
                // 任意邮箱，RUN_KEY 也不进查询串。
                try {
                    $this->notifyUser((string)$job['user_id'], (int)$job['zid']);
                } catch (Throwable $exception) {
                    // 上游/SMTP 异常只影响本条任务，重试下轮再投递
                    TaskLogs::operateExecuteLog('epic', (string)$job['user_id'], $job['do'],
                        '[重试中] 邮件通知异常，已安排稍后重试');
                    continue;
                }
            }
            Info::recordRun(100);
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
            Jobs::updateJobInfo('epic', $job['do'], $job['user_id'], [ // 更新任务执行信息
                'lastExecute' => date("Y-m-d H:i:s"),
                'nextExecute' => $nextExecute,
            ]);
        }
        return resultJson(1000, '执行任务成功');
    }

    public function notify()
    {
        // 旧的 notify 端点曾允许持有 RUN_KEY 的调用方向任意邮箱发信；
        // 邮件现在由 index() 在本进程内直接投递，此端点保留但不再接受
        // GET 收件人。外部调用一律拒绝。
        return resultJson(-1001, 'RunKey Access Denied!');
    }

    private function notifyUser(string $to, int $zid): void
    {
        $this->send_mail($to, 'Epic游戏商城周免领取通知', $this->get_email_template($zid), $zid);
    }

    private function get_email_template($zid)
    {
        $web  = Weblist::where('web_id', '=', $zid)->find();
        $obj = new \epic\Epic();
        $_html = '';
        foreach ($obj->getWeeklyFreeGames() as $weeklyFreeGame) {
            // 上游标题/描述/图片是外部数据，进邮件 HTML 前先转义
            $image = htmlspecialchars((string)$weeklyFreeGame['image'], ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars((string)$weeklyFreeGame['title'], ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars((string)$weeklyFreeGame['description'], ENT_QUOTES, 'UTF-8');
            $productUrl = htmlspecialchars((string)$weeklyFreeGame['productUrl'], ENT_QUOTES, 'UTF-8');
            $_html .= '<tr>
                                <td style="text-align: center; padding: 30px 30px 0;">
                                    <img style="height: 300px;" src="'. $image .'" alt="image">
                                </td>
                            </tr>
                            <tr>
                                <td style="text-align:center;padding: 15px 30px 30px 30px;">
                                    <h2 style="font-size: 24px; color: #6576ff; font-weight: 600; margin-bottom: 8px;">'. $title .'</h2>
                                    <p style="margin-bottom: 16px;">'. $description .'</p>
                                    <a href="'. $productUrl .'" style="background-color:#6576ff;border-radius:4px;color:#ffffff;display:inline-block;font-size:13px;font-weight:600;line-height:38px;text-align:center;text-decoration:none;text-transform: uppercase; padding: 0 30px">点我领取</a>
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
