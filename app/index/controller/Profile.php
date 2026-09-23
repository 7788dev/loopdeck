<?php

declare(strict_types=1);

namespace app\index\controller;

use app\index\model\Accounts;
use app\index\model\Jobs;
use app\service\AccountSnapshot;
use app\service\PlatformRegistry;
use think\facade\Request;
use think\facade\Session;
use think\facade\View;

final class Profile
{
    protected $middleware = [\app\middleware\CheckLoginUser::class, \app\middleware\CheckAjaxRequest::class];

    public function refresh()
    {
        if (!Request::isPost()) {
            return resultJson(0, '请使用 POST 请求');
        }
        $type = (string)Request::post('type', '');
        $userId = (string)Request::post('user_id', '');
        if (!in_array($type, array_merge(['netease', 'bilibili'], PlatformRegistry::types()), true)) {
            return resultJson(0, '不支持的账号类型');
        }
        $account = Accounts::where('uid', (int)Session::get('user.uid'))->where('zid', 1)
            ->where('type', $type)->where('user_id', $userId)->find();
        if (!$account) {
            return resultJson(0, '账号不存在或无权访问');
        }
        // Release PHP's session lock before slow I/O so this refresh does not
        // serialize other requests made by the same browser.
        Session::save();
        $snapshot = (new AccountSnapshot())->refresh($account->toArray());
        if ($snapshot['account_invalid']) {
            $changed = Accounts::where('id', (int)$account['id'])->where('data', (string)$account['data'])
                ->where('state', 1)->update(['state' => 0]);
            if ($changed) {
                Jobs::where('uid', (int)$account['uid'])->where('type', $type)->where('user_id', $userId)
                    ->update(['state' => -1]);
            }
        }
        // Check-in platforms render their own overview partial.
        $checkin = PlatformRegistry::supports($type);
        return resultJson(1, $snapshot['warning'] ?: '账号信息已更新', [
            'html' => View::fetch($checkin ? "console/{$type}/profile" : 'console/common/profile', [
                'snapshot' => $snapshot, 'platform' => $checkin ? PlatformRegistry::get($type) : [],
            ]),
        ]);
    }
}
