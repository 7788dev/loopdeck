<?php

namespace app\install\validate;

use think\Validate;

class Install extends Validate
{
    protected $rule = [
        'install-db-hostname|数据库地址' => 'require|max:255',
        'install-db-hostport|数据库端口' => 'require|integer|between:1,65535',
        'install-db-database|数据库名称' => 'require|alphaDash|max:64',
        'install-db-username|数据库用户名' => 'require|max:128',
        'install-db-password|数据库密码' => 'max:255',
        'install-admin-qq|联系 QQ' => 'require|number|length:5,15',
        'install-admin-username|管理员用户名' => 'require|alphaDash|length:5,25',
        'install-admin-password|管理员密码' => 'require|alphaNum|length:6,64',
        'install-admin-password-confirm|确认密码'
            => 'require|confirm:install-admin-password',
    ];

    protected $scene = [
        'install' => [
            'install-db-hostname',
            'install-db-hostport',
            'install-db-database',
            'install-db-username',
            'install-db-password',
            'install-admin-qq',
            'install-admin-username',
            'install-admin-password',
            'install-admin-password-confirm',
        ],
    ];
}
