<?php
declare (strict_types=1);

namespace app\admin\controller;

use app\admin\model\Notice;
use app\admin\model\Weblist;
use app\index\controller\Common;
use app\index\model\Info;
use app\index\model\Jobs;
use app\index\model\Users;
use app\service\SystemUpdater;
use think\facade\View;

class System extends Common
{

    protected $middleware = [
        'app\middleware\CheckLoginUser',
        'app\middleware\CheckUserPower',
    ];

    public function index()
    {
        View::assign([
            'webTitle' => '后台管理',
            'job_count' => Jobs::jobCount(),
            'execute_count' => Info::executeCount(),
            'user_count' => Users::userCount(),
            'agent_count' => Users::agentCount(),
            'notices' => Notice::getAdminNoticeList(),
            'user_qq' => Weblist::where('web_id', '=', 1)->find()['user_qq'],
        ]);
        return View::fetch('system/index');
    }

    public function set($act = null)
    {
        switch ($act) {
            case 'info':
                View::assign('webTitle', '网站信息设置');
                return View::fetch('/system/set/info');
            case 'plugin':
                View::assign('webTitle', '功能插件设置');
                return View::fetch('system/set/plugin');
            case 'cron':
                if (WEB_ID != 1) {
                    View::assign([
                        'msg' => '非法请求',
                        'url' => '/index/console'
                    ]);
                    exit(View::fetch('common/alert'));
                }
                View::assign('webTitle', '任务监控列表');
                return View::fetch('system/set/cron');
            case 'reg':
                View::assign('webTitle', '注册赠送配置');
                return View::fetch('system/set/reg');
            case 'mail':
                View::assign('webTitle', '邮箱信息设置');
                return View::fetch('system/set/mail');
            case 'template':
                View::assign('webTitle', '前台模板设置');
                return View::fetch('system/set/template');
            default:
                return response('未知的系统设置页面', 404);
        }
    }

    public function pay($act = null)
    {
        switch ($act) {
            case 'set':
                View::assign('webTitle', '网站支付配置');
                return View::fetch('/system/pay/set');
                break;
            case 'order':
                View::assign('webTitle', '网站订单列表');
                return View::fetch('system/pay/order');
                break;
            case 'vip':
                View::assign('webTitle', 'VIP价格设置');
                return View::fetch('system/pay/vip');
                break;
            case 'quota':
                View::assign('webTitle', '配额价格设置');
                return View::fetch('system/pay/quota');
                break;
            case 'agent':
                View::assign('webTitle', '代理价格设置');
                return View::fetch('system/pay/agent');
                break;
            case 'site':
                if (WEB_ID != 1) {
                    View::assign([
                        'msg' => '非法请求',
                        'url' => '/index/console'
                    ]);
                    exit(View::fetch('common/alert'));
                }
                View::assign('webTitle', '分站价格设置');
                return View::fetch('system/pay/site');
                break;
        }
    }

    public function task($act = null)
    {
        if (WEB_ID != 1) {
            View::assign([
                'msg' => '非法请求',
                'url' => '/index/console'
            ]);
            exit(View::fetch('common/alert'));
        }
        switch ($act) {
            case 'set':
                View::assign('webTitle', '系统任务设置');
                return View::fetch('system/task/set');
                break;
            case 'list':
                View::assign('webTitle', '系统任务列表');
                return View::fetch('system/task/list');
                break;
        }
    }

    public function data($act = null)
    {
        switch ($act) {
            case 'users':
                View::assign('webTitle', '用户数据管理');
                return View::fetch('system/data/users');
                break;
            case 'tasks':
                View::assign('webTitle', '任务数据管理');
                return View::fetch('system/data/accounts');
                break;
            case 'kms':
                View::assign('webTitle', '卡密数据管理');
                return View::fetch('system/data/kms');
                break;
            case 'notices':
                View::assign('webTitle', '公告数据管理');
                return View::fetch('system/data/notices');
                break;
            case 'sites':
                if (WEB_ID != 1) {
                    View::assign([
                        'msg' => '非法请求',
                        'url' => '/index/console'
                    ]);
                    exit(View::fetch('common/alert'));
                }
                View::assign('webTitle', '分站数据管理');
                return View::fetch('system/data/sites');
                break;
        }
    }

    public function update()
    {
        if (WEB_ID != 1) {
            View::assign([
                'msg' => '非法请求',
                'url' => '/index/console'
            ]);
            exit(View::fetch('common/alert'));
        }
        View::assign((new SystemUpdater())->status());
        View::assign('webTitle', '系统更新');
        return View::fetch('system/update');
    }

}
