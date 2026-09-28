<?php

declare(strict_types=1);

require __DIR__ . '/FunctionalDatabaseBootstrap.php';

use think\facade\Db;

fixtureTable('tasks', 'id INTEGER PRIMARY KEY, type TEXT, execute_name TEXT, name TEXT, describe TEXT, icon TEXT,
    execute_rate INTEGER, more INTEGER, state INTEGER, vip INTEGER, "order" INTEGER');
fixtureTable('jobs', 'id INTEGER PRIMARY KEY, uid INTEGER, type TEXT, user_id TEXT, do TEXT, state INTEGER, nextExecute INTEGER');
fixtureTable('notice', 'id INTEGER PRIMARY KEY AUTOINCREMENT, zid INTEGER, type INTEGER, title TEXT, content TEXT, alert INTEGER, addtime INTEGER');
fixtureTable('accounts', 'id INTEGER PRIMARY KEY, uid INTEGER, zid INTEGER, type TEXT, user_id TEXT');
fixtureTable('task_logs', 'id INTEGER PRIMARY KEY, type TEXT, user_id TEXT');
Db::name('tasks')->insert(['id' => 1, 'type' => 'heybox', 'execute_name' => 'sign', 'name' => '签到', 'vip' => 0]);
Db::name('jobs')->insert(['id' => 1, 'uid' => 1, 'type' => 'heybox', 'user_id' => '42', 'do' => 'sign', 'state' => 0]);
fixtureRequest(['id' => 1, 'name' => '每日签到']);
$admin = new app\admin\controller\Ajax();
functionalCheck($admin->task('set')->getData()['code'] === 1, 'Admin task copy edit failed');
functionalCheck((int)Db::name('jobs')->value('state') === 0, 'Editing task copy enabled a user-paused job');
fixtureRequest(['id' => 1, 'name' => '每日签到', 'execute_name' => 'unexpected']);
$admin->task('set');
functionalCheck(Db::name('tasks')->value('execute_name') === 'sign', 'Admin copy edit changed executable method');
functionalCheck(app\admin\model\Notice::add(['type' => 1, 'title' => '测试公告', 'content' => '内容'])->getData()['code'] === 1, 'Notice creation failed');
functionalCheck(app\admin\model\Notice::updateByid(1, ['title' => '已修改']), 'Notice edit failed');
functionalCheck(app\admin\model\Notice::delByid(1), 'Notice delete failed');
functionalCheck(!app\admin\model\Notice::delByid(1), 'Repeated notice delete succeeded');
functionalCheck(!app\index\model\Users::adminDelByUid(1), 'Super administrator could be deleted');
Db::name('users')->insert(['uid' => 2, 'web_id' => 1]);
Db::name('accounts')->insert(['id' => 2, 'uid' => 2, 'zid' => 1, 'type' => 'heybox', 'user_id' => '99']);
Db::name('jobs')->insert(['id' => 2, 'uid' => 2, 'type' => 'heybox', 'user_id' => '99']);
functionalCheck(app\index\model\Users::adminDelByUid(2), 'User deletion failed');
functionalCheck(Db::name('accounts')->where('uid', 2)->count() === 0, 'Deleted user left account ownership blocking rebinding');
functionalCheck(Db::name('jobs')->where('uid', 2)->count() === 0, 'Deleted user left runnable tasks');
functionalCheck(Db::name('jobs')->where('uid', 1)->count() === 1, 'User deletion affected another user');
foreach ([1, 2, 3] as $id) Db::name('task_logs')->insert(['id' => $id, 'type' => 'heybox', 'user_id' => '42']);
fixtureRequest(['limit' => 0]);
functionalCheck($admin->clearLogs()->getData()['code'] === 0, 'Unbounded log cleanup accepted');
fixtureRequest(['limit' => 2]);
functionalCheck($admin->clearLogs()->getData()['code'] === 1, 'Log cleanup endpoint failed');
functionalCheck(Db::name('task_logs')->column('id') === [3], 'Log cleanup did not preserve newest rows');
echo "Admin task preferences, notices and user protection tests passed\n";

