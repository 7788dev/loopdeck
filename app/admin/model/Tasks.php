<?php

namespace app\admin\model;

use think\Model;

class Tasks extends Model
{
    public function getAllTask()
    {
        return \app\index\model\Tasks::visibleQuery()->order('type', 'asc')
            ->select();
    }

    public function getById($id)
    {
        if ($result = \app\index\model\Tasks::visibleQuery()->where('id', '=', $id)->find()) {
            return $result;
        }
        return false;
    }

    public function addTask($data = [])
    {
        if ($result = $this->insert([
            'type' => $data['type'],
            'name' => $data['name'],
            'describe' => $data['describe'],
            'icon' => $data['icon'],
            'execute_name' => $data['execute_name'],
            'more' => $data['more'],
            'vip' => $data['vip'],
            'time' => date('Y-m-d H:i:s'),
        ])) {
            return $result;
        }
        return false;
    }
}
