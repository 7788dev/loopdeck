<?php

namespace app\cron\controller;

use app\index\model\Accounts;
use app\index\model\TaskLogs;
use app\index\model\Jobs;
use app\index\model\Users;
use app\service\NotificationService;
use think\facade\Config;
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

    /** Daily listening verification is internal until an outcome is final. */
    public static function shouldReportTaskStatus(string $type, string $task, string $status): bool
    {
        return $type !== 'netease' || $task !== 'daka_new' || $status !== '重试中';
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

}
