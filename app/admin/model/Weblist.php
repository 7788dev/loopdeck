<?php

namespace app\admin\model;

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
        if ((int)$id !== 1 || !is_array($data)) {
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

}
