<?php

namespace iqiyi;

use GuzzleHttp\Client;

class Iqiyi
{
    private static $dfp = "a06b9bfd0fde4543268c6251c5c1ccf024ea778800482d4dfd00aa811e9ed56155";
    private static $user_agent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.69 Safari/537.36 Edg/95.0.1020.44";    //爱奇艺指纹
    public $cookiezt = 0;

    public function __construct($P00001 = null, $P00003 = null, $config = [])
    {
        $this->P00001 = $P00001;
        $this->P00003 = $P00003;
        $this->config = $config;
        if ($P00001 && $P00003) {
            $this->cookie = "P00001={$P00001}; P00003={$P00003};";
        }
    }

    public function getLoginToken()
    {
        $url = 'https://passport.iqiyi.com/apis/qrcode/gen_login_token.action';
        $payload = [
            'agenttype' => 1,
            'device_name' => '网页端',
            'formSDK' => 1,
            'ptid' => '01010021010000000000',
            'sdk_version' => '1.0.0',
            'surl' => 1,
        ];
        $raw = $this->curl('POST', $url, $payload);
        $de_raw = json_decode($raw['body'], true);
        return resultArray(200, '获取Token成功', ['token' => $de_raw['data']['token'], 'url' => $de_raw['data']['url'],]);
    }

    public function qrLogin($token)
    {
        $url = 'https://passport.iqiyi.com/apis/qrcode/is_token_login.action';
        $payload = [
            'agenttype' => 1,
            'formSDK' => 1,
            'ptid' => '01010021010000000000',
            'sdk_version' => '1.0.0',
            'token' => $token,
        ];
        $raw = $this->curl('POST', $url, $payload);
        $de_raw = json_decode($raw['body'], true);
        if ($de_raw['code'] == 'A00001') {
            return resultArray(201, '手机端尚未确认');
        } elseif ($de_raw['code'] == 'P00501') {
            return resultArray(202, 'Token错误，请重新获取二维码');
        } elseif ($de_raw['code'] == 'A00000') {
            preg_match('/P00004=(.*?)\;/', $raw['header'], $P00004);
            preg_match('/P00001=(.*?)\;/', $raw['header'], $P00001);
            preg_match('/P00007=(.*?)\;/', $raw['header'], $P00007);
            preg_match('/P00003=(.*?)\;/', $raw['header'], $P00003);
            preg_match('/P00010=(.*?)\;/', $raw['header'], $P00010);
            preg_match('/P01010=(.*?)\;/', $raw['header'], $P01010);
            preg_match('/P00PRU=(.*?)\;/', $raw['header'], $P00PRU);
            $data = [
                'uid' => $de_raw['data']['userinfo']['uid'],
                'nickname' => filterEmoji($de_raw['data']['userinfo']['nickname']),
                'icon' => $de_raw['data']['userinfo']['icon'],
                'P00004' => $P00004[1],
                'P00001' => $P00001[1],
                'P00007' => $P00007[1],
                'P00003' => $P00003[1],
                'P00010' => $P00010[1],
                'P01010' => $P01010[1],
                'P00PRU' => $P00PRU[1],
                'dfp' => 'a100f5e778b5ba41e683ebfba7da5e825cff2f079721a89b118d95f6a7eaa99f62',
            ];
            return resultArray(200, '登录成功', $data);
        } else {
            return resultArray(203, $de_raw['msg']);
        }
    }

    public function get_info()
    {
        $url = 'http://serv.vip.iqiyi.com/vipgrowth/query.action';
        $payload = [];
        $res = $this->curl('GET', $url, $payload, $this->cookie);
        $arr = json_decode($res['body'], true);
        return $arr;
    }

    public function member_sign()
    {
        $sign_data = [
            "agentType" => "1",
            "agentversion" => "1.0",
            "appKey" => "basic_pcw",
            "authCookie" => $this->P00001,
            "qyid" => md5($this->strRandom(16)),
            "task_code" => "natural_month_sign",
            "timestamp" => $this->time_13(),
            "typeCode" => "point",
            "userId" => $this->P00003,
        ];
        $payload = [
            "agentType" => "1",
            "agentversion" => "1",
            "authCookie" => $this->P00001,
            "qyid" => md5($this->strRandom(16)),
            "taskCode" => "iQIYI_mofhr",
            "verticalCode" => "iQIYI"
        ];
        $sign = $this->splice('|', $sign_data, 'UKobMjDMsDoScuWOfp6F');
        $url = 'https://community.iqiyi.com/openApi/task/execute?' . http_build_query($sign_data) . '&sign=' . $sign . '';
        $res = $this->curl('POST', $url, $sign_data, $this->cookie, ["natural_month_sign" => $payload]);
        $arr = json_decode($res['body'], true);
        if ($arr['code'] == 'A00000') {
            if ($arr['data']['code'] == 'A0000') {
                $quantity = $arr['data']['data']['rewards'][0]['rewardCount']; // 积分
                $addgrowthvalue = $arr['data']['data']['rewards'][0]['rewardCount']; // 新增成长值
                $continued = $arr['data']['signDays']; // 签到天数
                return resultArray(200, '签到成功：获得积分' . $quantity . '成长值' . $addgrowthvalue . '累计签到' . $continued . '天');
            } else {
                return resultArray(201, '签到失败：' . $arr['data']['msg']);
            }
        } else {
            return resultArray(201, '签到失败' . $arr['message']);
        }
    }

    public function web_sign()
    {
        $web_sign_data = [
            "agenttype" => "1",
            "agentversion" => "0",
            "appKey" => "basic_pca",
            "appver" => "0",
            "authCookie" => $this->P00001,
            "channelCode" => "sign_pcw",
            //"dfp"=> $this->dfp,
            "scoreType" => "1",
            "srcplatform" => "1",
            "typeCode" => "point",
            "userId" => $this->P00003,
            "user_agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.51 Safari/537.36 Edg/99.0.1150.39",
            "verticalCode" => "iQIYI"
        ];
        $sign = $this->splice('|', $web_sign_data, 'DO58SzN6ip9nbJ4QkM8H');
        $url = 'https://community.iqiyi.com/openApi/score/add?' . $this->splice('&', $web_sign_data) . '&sign=' . $sign;

        $res = $this->curl('POST', $url, [], $this->cookie);
        $arr = json_decode($res['body'], true);
        if ($arr['data'][0]['code'] == 'A0000') {
            $quantity = $arr['data'][0]['score'];
            $continued = $arr['data'][0]['continuousValue'];
            return resultArray(200, '网页端签到成功：获得积分' . $quantity . '，累计签到' . $continued . '天');
        } else {
            return resultArray(201, '网页端签到失败：' . $arr['data'][0]['message']);
        }
    }

    public function lottery()
    {
        $url = "https://iface2.iqiyi.com/aggregate/3.0/lottery_activity";
        $payload = [
            "app_k" => "0",
            "app_v" => "0",
            "platform_id" => 10,
            "dev_os" => "2.0.0",
            "dev_ua" => "COL-AL10",
            "net_sts" => 1,
            "qyid" => "2655b332a116d2247fac3dd66a5285011102",
            "psp_uid" => $this->P00003,
            "psp_cki" => $this->P00001,
            "psp_status" => 3,
            "secure_v" => 1,
            "secure_p" => "0",
            "req_sn" => round(time() * 1000)
        ];
        $payload['lottery_chance'] = 1;
        $res = $this->curl('GET', $url, $payload, $this->cookie);
        $arr = json_decode($res['body'], true);
        if ($arr['code'] == 0 && $arr['daysurpluschance'] == 0) {
            return resultArray(200, '您的抽奖次数已经用完，明日再来吧');
        } else {
            $ret = "";
            $payload['lottery_chance'] = 0;
            for ($i = 1; $i <= 3; $i++) {
                $res = $this->curl('GET', $url, $payload, $this->cookie);
                $arr = json_decode($res['body'], true);
                if ($arr['code'] == 0) {
                    $chance = @$arr['daysurpluschance']; // 剩余抽奖次数
                    $award = @$arr['awardName']; // 奖品名称
                    $ret .= '第' . $i . '次抽奖：' . $award . '&nbsp;';
                } else {
                    $ret .= $ret .= '第' . $i . '次抽奖失败 ' . $arr['kv']['msg'];
                }
            }
            return resultArray(200, $ret);
        }
    }

    /**
     * 积分中心任务
     */
    public function score()
    {
        $this->score_hot();
        $this->score_video();
        $this->score_sign();
        return resultArray(200, '积分中心任务完成,预计收益40~50积分');
    }

    /**
     * 积分中心访问热点任务
     */
    public function score_hot()
    {
        $data = 'agenttype=1|agentversion=0|appKey=basic_pcw|appver=0|authCookie=' . $this->P00001 . '|channelCode=paopao_pcw|dfp=' . self::$dfp . '|scoreType=1|srcplatform=1|typeCode=point|userId=' . $this->P00003 . '|user_agent=' . self::$user_agent . '|verticalCode=iQIYI|UKobMjDMsDoScuWOfp6F';
        $sign = $this->getsgin($data);
        //热点任务
        $url = 'https://community.iqiyi.com/openApi/task/complete?agenttype=1&agentversion=0&appKey=basic_pcw&appver=0&authCookie=' . $this->P00001 . '&channelCode=paopao_pcw&dfp=' . self::$dfp . '&scoreType=1&srcplatform=1&typeCode=point&userId=' . $this->P00003 . '&user_agent=' . urlencode(self::$user_agent) . '&verticalCode=iQIYI&sign=' . $sign;
        //领取积分
        $url2 = 'https://community.iqiyi.com/openApi/score/getReward?agenttype=1&agentversion=0&appKey=basic_pcw&appver=0&authCookie=' . $this->P00001 . '&channelCode=paopao_pcw&dfp=' . self::$dfp . '&scoreType=1&srcplatform=1&typeCode=point&userId=' . $this->P00003 . '&user_agent=' . urlencode(self::$user_agent) . '&verticalCode=iQIYI&sign=' . $sign;
        $res = $this->http_request($url);
        $drew = $this->http_request($url2);
        if ($res->code == "A0002") {
            return resultArray(202, '任务次数已经到达上限');
        } elseif ($res->code == "A00000" && $drew->code == "A00000") {
            return resultArray(200, '领取积分成功');
        }
        return resultArray(200, '任务执行成功');
    }

    /**
     * 积分中心观看视频任务
     */
    public function score_video()
    {
        $data = 'agenttype=1|agentversion=0|appKey=basic_pcw|appver=0|authCookie=' . $this->P00001 . '|channelCode=view_pcw|dfp=' . self::$dfp . '|scoreType=1|srcplatform=1|typeCode=point|userId=' . $this->P00003 . '|user_agent=' . self::$user_agent . '|verticalCode=iQIYI|UKobMjDMsDoScuWOfp6F';
        $sign = $this->getsgin($data);
        $url = 'https://community.iqiyi.com/openApi/score/add?agenttype=1&agentversion=0&appKey=basic_pcw&appver=0&authCookie=' . $this->P00001 . '&channelCode=view_pcw&dfp=' . self::$dfp . '&scoreType=1&srcplatform=1&typeCode=point&userId=' . $this->P00003 . '&user_agent=' . urlencode(self::$user_agent) . '&verticalCode=iQIYI&sign=' . $sign;
        for ($i = 0; $i < 3; $i++) {
            $res = $this->http_request($url);
        }
        if ($res->code == "A00000") {
            if ($res->data[0]->code == "A0002") {
                return resultArray(200, '任务次数已经到达上限');
            }
            return resultArray(200, '观看视频成功');
        }
        return resultArray(200, '任务执行成功');
    }

    /**
     * 积分中心签到
     */
    public function score_sign()
    {
        $data = 'agenttype=1|agentversion=0|appKey=basic_pca|appver=0|authCookie=' . $this->P00001 . '|channelCode=sign_pcw|dfp=' . self::$dfp . '|scoreType=1|srcplatform=1|typeCode=point|userId=' . $this->P00003 . '|user_agent=' . self::$user_agent . '|verticalCode=iQIYI|DO58SzN6ip9nbJ4QkM8H';
        $sgin = $this->getsgin($data);
        $url = 'https://community.iqiyi.com/openApi/score/add?authCookie=' . $this->P00001 . '&userId=' . $this->P00003 . '&channelCode=sign_pcw&agenttype=1&agentversion=0&appKey=basic_pca&appver=0&srcplatform=1&typeCode=point&verticalCode=iQIYI&scoreType=1&user_agent=' . urlencode(self::$user_agent) . '&dfp=' . self::$dfp . '&sign=' . $sgin;
        $res = $this->http_request($url);
        if ($res->code == "A00000") {
            if ($res->data[0]->code == "A0002") {
                return resultArray(200, '任务次数已经到达上限');
            }
            return resultArray(200, '签到成功');
        }
        return resultArray(200, '任务执行成功');
    }

    /**
     * VIP日常任务
     */
    public function vipDailyTask()
    {
        $this->vip_browse();
        $this->vip_hit();
        $this->vip_watch();
        $this->vip_video();
        return resultArray(200, '会员中心日常任务完成');
    }

    /**
     * VIP任务浏览福利
     */
    public function vip_browse()
    {
        $taskCode = 'b6e688905d4e7184';
        return $this->start($taskCode);
    }

    /**
     * VIP任务看热播榜
     */
    public function vip_hit()
    {
        $taskCode = 'a7f02e895ccbf416';
        return $this->start($taskCode);
    }

    /**
     * VIP任务观影30分钟
     */
    public function vip_watch()
    {
        $taskCode = 'WatchVideo60mins';
        return $this->start($taskCode);
    }


    /**
     * VIP任务播放批量
     */
    public function vip_video()
    {
        $curl = [];
        for ($i=0; $i < 60; $i++) {
            $curl[$i] = curl_init();
            curl_setopt($curl[$i], CURLOPT_URL, "https://msg.qy.net/b?u=cafd40897ee09c435960272ff3a4e291&pu=$this->P00003&p1=1_10_101&v=5.2.66&ce=52584c09397f85e3c3134bf84fd422fc&de=1629952975.1629952975.1629952975.1&c1=1&ve=5f04795914b9c064b415353de37d4b16&ht=1&pt=2031.264453&isdm=0&duby=0&ra=2&clt=&ps2=DIRECT&ps3=&ps4=&br=mozilla%2F5.0%20(windows%20nt%2010.0%3B%20win64%3B%20x64)%20applewebkit%2F537.36%20(khtml%2C%20like%20gecko)%20chrome%2F95.0.4638.69%20safari%2F537.36&mod=cn_s&purl=https%3A%2F%2Fwww.iqiyi.com%2Fv_2cwmbteaa18.html&tmplt=1&ptid=01010031010000000000&os=window&nu=0&vfm=&coop=&ispre=0&videotp=0&drm=&plyrv=&rfr=https%3A%2F%2Fwww.iqiyi.com%2Fdianying%2F%3Fvfrm%3Dpcw_home%26vfrmblk%3DC%26vfrmrst%3D712211_channel_dianying&fatherid=8681540158522400&stauto=1&algot=connectFailed&vvfrom=&vfrmtp=1&pagev=playpage_adv_xb&engt=2&ldt=1&krv=1.1.85&wtmk=0&duration=5413625&bkt=&e=&stype=&r_area=&r_source=&s4=&s3=&vbr=61548&mft=0&ra1=2&wint=3&s2=&bw=3.1&ntwk=18&dl=49.875&rn=0.34701240615020845&dfp=a1f107016de1864272af07902f04f278ced9f2124e6cc71e0098f948eb5d31e471&stime=1636396937147&r=8681540158522400&hu=1&t=2&tm=120&_=1636396937147");
            curl_setopt($curl[$i], CURLOPT_HTTPHEADER,array('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.69 Safari/537.36'));
            curl_setopt($curl[$i], CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($curl[$i], CURLOPT_SSL_VERIFYHOST, FALSE);
        }
        // 创建批处理cURL句柄
        $mh = curl_multi_init();
        foreach ($curl as $k => $v){
            curl_multi_add_handle($mh,$v);
        }
        $running=null;
        do {
            curl_multi_exec($mh,$running);
        } while ($running > 0);
        // 关闭全部句柄
        foreach ($curl as $k => $v){
            curl_multi_remove_handle($mh,$v);
        }
        curl_multi_close($mh);
    }

    /**
     * VIP其他任务
     */
    public function vipOtherTask()
    {
        $this->vip_test();
        $this->vip_baidu();
        $this->vip_upgrade();
        return resultArray(200, '会员中心其他任务完成');
    }

    /**
     * VIP任务权益答题
     */
    public function vip_test()
    {
        $taskCode = 'RightsTest';
        return $this->start($taskCode);
    }

    /**
     * VIP百度借钱
     */
    public function vip_baidu()
    {
        $taskCode = '1231231231';
        return $this->start($taskCode);
    }

    /**
     * VIP升级权益
     */
    public function vip_upgrade()
    {
        $taskCode = 'aa9ce6f915bea560';
        return $this->start($taskCode);
    }

    /**
     * @param string $taskCode
     * @return mixed
     */
    public function start(string $taskCode): mixed
    {
        $url = [
            'https://tc.vip.iqiyi.com/taskCenter/task/joinTask?P00001=' . $this->P00001 . '&taskCode=' . $taskCode . '&platform=bb136ff4276771f3&lang=zh_CN&app_lm=cn&qyid=dc947513aafa9f99283b39ff339a4280110c',       //开启任务接口
            'https://tc.vip.iqiyi.com/taskCenter/task/notify?taskCode=' . $taskCode . '&P00001=' . $this->P00001 . '&platform=abaf99397476e27d&lang=cn',      //完成任务接口
            'https://tc.vip.iqiyi.com/taskCenter/task/getTaskRewards?P00001=' . $this->P00001 . '&taskCode=' . $taskCode . '&platform=bb136ff4276771f3&lang=zh_CN&app_lm=cn&deviceID=dc947513aafa9f99283b39ff339a4280110c&dfp=&fv=8810d5688ee1a7a6'  //领取奖励接口
        ];
        for ($i = 0; $i < 3; $i++) {
            $return = $this->http_request($url[$i]);
            if ($i == 1) {
                sleep(3);
            }
        }
        return $return;
    }

    public function strRandom($len)
    {
        $chars = array(
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $charsLen = count($chars) - 1;
        shuffle($chars);
        $output = '';
        for ($i = 0; $i < $len; $i++) {
            $output .= $chars[mt_rand(0, $charsLen)];
        }
        return $output;
    }

    public function time_13()
    {
        return round(time() * 1000);
    }

    public function splice($str, $para, $sign = null)
    {
        $arg = "";
        foreach ($para as $key => $val) {
            $arg .= $key . "=" . $val . $str;
        }
        //去掉最后一个字符
        $arg = substr($arg, 0, strlen($arg) - 1);
        if ($sign) {
            $arg = $arg . '|' . $sign;
            return md5($arg);
        }
        return $arg;
    }

    /**
     * 计算sgin
     */
    private function getsgin($data)
    {
        return md5($data);
    }

    /**
     * HTTP请求
     */
    private function http_request($url, $data = null)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.69 Safari/537.36'));
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $output = curl_exec($curl);
        curl_close($curl);
        $output = json_decode($output);
        return $output;
    }

    protected function curl(string $method = '', string $url = '', array $params = [], string $cookie = null, array $json = []): array
    {
        $Client = new Client([
            'timeout' => 30.0,
            'http_errors' => false,
            'verify' => false,
        ]);
        $header = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.4844.51 Safari/537.36 Edg/99.0.1150.39',
            'Cookie' => $cookie,
        ];
        $data = ['connect_timeout' => 30, 'headers' => $header];
        if ($method == 'POST') {
            if ($json) {
                $data['headers']['Content-Type'] = "application/json";
                $data['json'] = $json;
            } else {
                $data['form_params'] = $params;
            }
        } else {
            $data['query'] = $params;
        }
        $response = $Client->request($method, $url, $data);
        $header = '';
        foreach ($response->getHeaders() as $name => $values) {
            $header .= $name . ': ' . implode(', ', $values) . "\r\n";
        }
        return [
            'header' => $header,
            'body' => $response->getBody()->getContents(),
        ];
    }

}