<?php

namespace app\admin\model;

use think\Model;

class Accounts extends Model
{
    /**
     * Apply the request's search filters to a query. Shared by the page query
     * and the count query so the total matches the rows actually shown.
     */
    private static function applySearchFilters($query, array $search)
    {
        if (!empty($search['uid'])) $query->where('uid', '=',  $search['uid']);
        if (!empty($search['user_id'])) $query->where('user_id', '=',  $search['user_id']);
        if (is_numeric($search['status'] ?? null)) $query->where('state', '=',  $search['status']);
        return $query;
    }

    public static function getAccountList()
    {
        $start = (int)input('post.start');
        $length = (int)input('post.length');
        $search = (array)(input('post.search') ?? []);

        $self = new static();
        $query = $self->alias('a');
        $query->where('zid', '=', WEB_ID);
        self::applySearchFilters($query, $search);

        // count 在独立查询上执行，避免聚合继承 ORDER BY/LIMIT 导致 total 错误
        $countQuery = (new static())->alias('a')->where('zid', '=', WEB_ID);
        self::applySearchFilters($countQuery, $search);
        $total = $countQuery->count('id');

        if ($result = $query->order('a.addtime desc')->withoutField('data')->limit($start, $length)->select()) {
            return [
                'total' => $total,
                'page' => input('post.page'),
                'data' => $result,
            ];
        }
        return false;
    }

    public static function getAllAccountList()
    {
        $start = (int)input('post.start');
        $length = (int)input('post.length');
        $search = (array)(input('post.search') ?? []);

        $self = new static();
        $query = $self->alias('a');
        self::applySearchFilters($query, $search);

        $countQuery = (new static())->alias('a');
        self::applySearchFilters($countQuery, $search);
        $total = $countQuery->count('id');

        if ($result = $query->order('a.addtime desc')->withoutField('data')->limit($start, $length)->select()) {
            return [
                'total' => $total,
                'page' => input('post.page'),
                'data' => $result,
            ];
        }
        return false;
    }

    public static function delByid($id, bool $cleanupState = true)
    {
        $account = \think\facade\Db::transaction(static function () use ($id) {
            $account = self::where('id', $id)->where('zid', WEB_ID)->lock(true)->find();
            if (!$account) {
                return null;
            }
            \think\facade\Db::name('jobs')->where('type', $account['type'])
                ->where('user_id', $account['user_id'])->where('uid', $account['uid'])->delete();
            if (!self::where('type', $account['type'])->where('user_id', $account['user_id'])->where('id', '<>', $account['id'])->find()) {
                \think\facade\Db::name('task_logs')->where('type', $account['type'])
                    ->where('user_id', $account['user_id'])->delete();
            }
            $data = $account->toArray();
            $account->delete();
            return $data;
        });
        if ($account && $cleanupState) {
            self::forgetAccountState($account);
        }
        return $account !== null;
    }

    public static function forgetAccountState(array $account): void
    {
        if ($account['type'] === 'netease' && !self::where('type', 'netease')->where('user_id', $account['user_id'])->find()) {
            try {
                (new \netease\Netease((string)$account['user_id'], '', '', [
                    'auto_anonymous_token' => false, 'cache_dir' => '',
                ]))->forgetDakaState();
            } catch (\Throwable $exception) {
                // The scheduler also prunes orphaned state files.
            }
        }
    }

    public static function delByUserid($uid)
    {
        $self = new static();
        if ($self->where('uid', '=', $uid)->where('zid', '=', WEB_ID)->delete()) {
            return true;
        }
        return false;
    }
    
    
}
