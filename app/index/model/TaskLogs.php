<?php

namespace app\index\model;

use think\Model;

class TaskLogs extends Model
{
    private static array $taskNameCache = [];

    public static function searchLogs($type, $user_id)
    {
        $self = new static();
        if ($result = $self->order('addtime desc')->where('type', '=', $type)->where('user_id', '=', $user_id)->limit(50)->select()) {
            return $result;
        }
        return false;
    }

    public static function deleteLogs($type, $user_id)
    {
        $self = new static();
        if ($result = $self->where('type', '=', $type)->where('user_id', '=', $user_id)->delete() !== false) {
            return $result;
        }
        return false;
    }
    
    public static function deleteLogsById($user_id)
    {
        $self = new static();
        if ($result = $self->where('user_id', '=', $user_id)->delete() !== false) {
            return $result;
        }
        return false;
    }

    public static function operateLog($data = [])
    {
        $self = new static();
        $data['do'] = self::taskDisplayName((string)$data['type'], (string)$data['do']);
        $insert = [
            'type' => $data['type'],
            'user_id' => $data['user_id'],
            'do' => $data['do'],
            'response' => $data['response'],
            'addtime' => date('Y-m-d H:i:s'),
        ];
        if ($self->field('type,user_id,do,response,addtime')->insert($insert)) {
            return true;
        }
        return false;
    }

    public static function operateExecuteLog($type, $user_id, $do, $response)
    {
        $self = new static();
        $do = self::taskDisplayName((string)$type, (string)$do);
        $insert = [
            'type' => $type,
            'user_id' => $user_id,
            'do' => $do,
            'response' => $response,
            'addtime' => date('Y-m-d H:i:s'),
        ];
        if ($self->field('type,user_id,do,response,addtime')->insert($insert)) {
            return true;
        }
        return false;
    }

    private static function taskDisplayName(string $type, string $task): string
    {
        $key = $type . '|' . $task;
        if (!array_key_exists($key, self::$taskNameCache)) {
            $name = Tasks::where('type', $type)
                ->where('execute_name', $task)
                ->value('name');
            self::$taskNameCache[$key] = is_string($name) && $name !== '' ? $name : $task;
        }

        return self::$taskNameCache[$key];
    }
}
