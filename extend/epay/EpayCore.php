<?php

namespace epay;

class EpayCore
{
    /**
     * 签名字符串
     * @param string $str 需要签名的字符串
     * @param string $key 私钥
     * @return string 签名结果
     */
    function md5Sign($str, $key)
    {
        return md5($str . $key);
    }

    /**
     * 验证签名
     * @param string $str 需要签名的字符串
     * @param string $sign 签名结果
     * @param string $key 私钥
     * @return bool 签名结果
     */
    function md5Verify($str, $sign, $key)
    {
        $mysgin = md5($str . $key);
        if ($mysgin == $sign) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
     * @param array $para 需要拼接的数组
     * @return string 拼接完成以后的字符串
     */
    function createLinkString($para)
    {
        $arg = "";
        foreach ($para as $key => $val) {
            $arg .= $key . "=" . $val . "&";
        }
        //去掉最后一个&字符
        $arg = substr($arg, 0, strlen($arg) - 1);
        //如果存在转义字符，那么去掉转义
//        if (get_magic_quotes_gpc()) {
//            $arg = stripslashes($arg);
//        }

        return $arg;
    }

    /**
     * 把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串，并对字符串做urlencode编码
     * @param $para 需要拼接的数组
     * return 拼接完成以后的字符串
     */
    function createLinkstringUrlencode($para) {
        $arg  = "";
        foreach ($para as $key=>$val) {
            $arg.=$key."=".urlencode($val)."&";
        }
        //去掉最后一个&字符
        $arg = substr($arg,0,-1);

        return $arg;
    }

    /**
     * 除去数组中的空值和签名参数
     * @param array $para 签名参数组
     * @return array 去掉空值与签名参数后的新签名参数组
     */
    function paraFilter($para)
    {
        $para_filter = array();
        foreach ($para as $key => $val) {
            if ($key != "sign" && $key != "sign_type" && $val != "") {
                $para_filter[$key] = $para[$key];
            }
        }
        return $para_filter;
    }

    /**
     * 对数组排序
     * @param array $para 排序前的数组
     * return 排序后的数组
     */
    function argSort($para)
    {
        ksort($para);
        reset($para);
        return $para;
    }
}

?>