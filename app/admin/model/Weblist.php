<?php

namespace app\admin\model;

use app\index\model\Users;
use think\facade\Db;
use think\middleware\AllowCrossDomain;
use think\Model;

class Weblist extends Model
{
    private const EDITABLE_FIELDS = [
        'webname',
        'title',
        'keywords',
        'description',
        'domain',
        'domain2',
        'user_qq',
        'icp',
        'index_bg',
        'index_mode',
        'index_url',
        'index_template',
        'login_template',
    ];

    /**
     * updateByWebid
     * @param $id
     * @param array $data
     * @return bool
     * @author BadCen
     */
    public static function updateByWebid($id, $data = [])
    {
        if (!is_array($data)) {
            return false;
        }
        $data = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));
        if ($data === []) {
            return false;
        }
        foreach ($data as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                return false;
            }
            $data[$key] = (string)$value;
        }
        if (isset($data['index_mode'])) {
            $mode = filter_var($data['index_mode'], FILTER_VALIDATE_INT);
            if (!in_array($mode, [1, 2, 3], true)) {
                return false;
            }
            $data['index_mode'] = $mode;
        }
        foreach (['index_template', 'login_template'] as $field) {
            if (isset($data[$field]) && preg_match('/\A[A-Za-z0-9_-]{1,20}\z/', $data[$field]) !== 1) {
                return false;
            }
        }
        foreach ($data as $key => $value) {
            $limit = $key === 'title' ? 20 : ($key === 'index_url' ? 64 : 255);
            if (is_string($value) && strlen($value) > $limit) {
                return false;
            }
        }

        $self = new static();
        return ($self->where('web_id', '=', $id)->update($data) !== false);
    }

    public static function configTableName(mixed $prefix): ?string
    {
        if (!is_string($prefix)
            || $prefix === ''
            || strlen($prefix) > 56
            || preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $prefix) !== 1
        ) {
            return null;
        }

        return $prefix . 'configs';
    }

    public static function findByWebid($id)
    {
        $self = new static();
        if ($result = $self->where('web_id', '=', $id)->find()) {
            return $result;
        }
        return false;
    }

    public static function delByid($id)
    {
        $self = new static();
        if ($self->where('web_id', '=', $id)->delete()) {
            return true;
        }
        return false;
    }

    public static function getSitesList()
    {
        $self = new static();
        return $self->order('web_id asc')->where('web_id', '<>', 1)->field('web_id,user_id,user_qq,webname,end_time,status')->select();
//        return $self->order('web_id asc')->select();
    }

    public static function add($data)
    {
        if ($data['domain'] == $_SERVER['HTTP_HOST']) {
            return resultJson(0, '分站域名不能和主站相同');
        }
        $prefix = get_Prefix() . '_';
        $data['prefix'] = $prefix;
        $data['sup_id'] = WEB_ID;
        $data['web_key'] = getRandStr(16);
        $data['start_time'] = date("Y-m-d H:i:s");
        if (!Users::where('uid', '=', $data['user_id'])->field('uid')->find()) {
            return resultJson(0, '该用户ID不存在');
        } elseif (Weblist::where('user_id', '=', $data['user_id'])->field('web_id')->find()) {
            return resultJson(0, '该用户已经开通过分站');
        } elseif (!check_mail($data['mail'])) {
            return resultJson(0, '站长邮箱格式不正确');
        } else {
            $self = new static();
            if ($webid = $self->field('sup_id,user_id,webname,domain,user_qq,mail,start_time,end_time,prefix,web_key')->insertGetId($data)) {
                Users::where('uid', '=', $data['user_id'])
                    ->update([
                        'power' => 6,
                        'web_id' => $webid
                    ]);
                $sqls = file_get_contents('./static/site.sql');
                $sqls = str_replace('cloud_', $prefix, $sqls);
                $explode = explode(';', $sqls);
                foreach ($explode as $sql) {
                    if ($sql = trim($sql)) {
                        Db::query($sql);
                    }
                }
                return resultJson(1, '添加分站成功');
            } else {
                return resultJson(1, '添加分站失败');
            }
        }
    }

    public static function templateCount(): int
    {
        $index_file_path = root_path() . "app" . DIRECTORY_SEPARATOR . "index" . DIRECTORY_SEPARATOR . "view" . DIRECTORY_SEPARATOR . "index";
        $login_file_path = root_path() . "app" . DIRECTORY_SEPARATOR . "index" . DIRECTORY_SEPARATOR . "view" . DIRECTORY_SEPARATOR . "login";
        $index_res = self::my_scandir($index_file_path);
        $login_res = self::my_scandir($login_file_path);
        $index_num = count($index_res);
        $login_num = count($login_res);
        return $index_num + $login_num;
    }

    private static function my_scandir($dir): array
    {
        if (!is_dir($dir) || ($handle = opendir($dir)) === false) {
            return [];
        }

        $files = [];
        try {
            while (($file = readdir($handle)) !== false) {
                if ($file !== '.' && $file !== '..') {
                    if (is_dir($dir . '/' . $file)) {
                        $files[$file] = self::my_scandir($dir . '/' . $file);
                    } else {
                        $files[] = $dir . '/' . $file;
                    }
                }
            }
        } finally {
            closedir($handle);
        }

        return $files;
    }

    public static function indexTemplateData(): array
    {
        $file_path = root_path() . "app" . DIRECTORY_SEPARATOR . "index" . DIRECTORY_SEPARATOR . "view" . DIRECTORY_SEPARATOR . "index";
        $res = self::my_scandir($file_path);
        $result = [];
        foreach ($res as $val) {
            foreach ($val as $row) {
                if (str_contains($row, "json")) {
                    $result[] = json_decode(file_get_contents($row),true);
                }
            }
        }
        return $result;
    }

    public static function loginTemplateData(): array
    {
        $file_path = root_path() . "app" . DIRECTORY_SEPARATOR . "index" . DIRECTORY_SEPARATOR . "view" . DIRECTORY_SEPARATOR . "login";
        $res = self::my_scandir($file_path);
        $result = [];
        foreach ($res as $val) {
            foreach ($val as $row) {
                if (str_contains($row, "json")) {
                    $result[] = json_decode(file_get_contents($row),true);
                }
            }
        }
        return $result;
    }
	
	private static function get_extension($file)
	{
       return pathinfo($file, PATHINFO_EXTENSION);
    }

}
