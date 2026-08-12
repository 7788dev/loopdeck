<?php
declare (strict_types=1);

namespace app\index\controller;

use app\index\model\Accounts;
use app\index\model\Users;
use app\index\model\Weblist;
use app\service\ApplicationVersion;
use netease\Qrcode;
use think\facade\Request;
use think\facade\View;

class Index extends Common
{
    public function _empty()
    {
        return $this->index();
    }

    public function index()
    {
        View::assign([
            'timeCount' => Weblist::start_Time(),
            'userCount' => Users::userCount(),
            // The old home page expected a QQ-avatar showcase collection that
            // was never assigned. Keep the section empty after removing QQ.
            'users' => [],
        ]);
        return View::fetch('index/index');
    }

    public function healthcheck()
    {
        return response('ok', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-LoopDeck-Version' => ApplicationVersion::current(),
        ]);
    }

    public function version()
    {
        $version = ApplicationVersion::current();

        return response($version, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-LoopDeck-Version' => $version,
        ]);
    }

    public function qrcode()
    {
        $name = trim((string)Request::get('name', ''));
        $account = Accounts::where('type', '=', 'qrcode')
            ->where('user_id', '=', $name)
            ->where('zid', '=', WEB_ID)
            ->find();
        if (!$account) {
            return response('收款码不存在', 404);
        }
        $data = unserialize((string)$account['data'], ['allowed_classes' => false]);
        if (!is_array($data)) {
            return response('收款码数据损坏', 500);
        }
        $agent = strtolower((string)Request::server('HTTP_USER_AGENT', ''));
        if (str_contains($agent, 'alipayclient')) {
            // This route is public and the target is stored by an ordinary
            // user, so only real http(s) payment links may be followed.
            $target = safe_http_url((string)($data['alipay_url'] ?? ''));
            if ($target === '') {
                return response('收款地址无效', 400);
            }
            return redirect($target);
        }
        $type = str_contains($agent, 'micromessenger') ? 'wechat' : 'qq';
        return View::fetch('index/default/qrcode', [
            'type' => $type,
            'url' => safe_http_url((string)($data[$type . '_url'] ?? '')),
            'name' => (string)($data['name'] ?? ''),
        ]);
    }

    public function createQrcode()
    {
        $text = urldecode((string)Request::get('text', ''));
        if ($text === '' || strlen($text) > 2048 || preg_match('/[\x00-\x1F]/', $text)) {
            return response('二维码内容无效', 400);
        }
        ob_start();
        (new Qrcode())->png($text, false, QR_ECLEVEL_M, 8, 2);
        $image = (string)ob_get_clean();
        return response($image, 200, ['Content-Type' => 'image/png']);
    }

}
