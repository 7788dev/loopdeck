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
        foreach (['index_template', 'login_template'] as $field) {
            if (!isset($data[$field])) {
                continue;
            }
            $templates = $field === 'index_template'
                ? self::indexTemplateData()
                : self::loginTemplateData();
            if (!in_array($data[$field], array_column($templates, 'id'), true)) {
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

    public static function templateCount(): int
    {
        return count(self::indexTemplateData()) + count(self::loginTemplateData());
    }

    /** 整体模板（原版/若依）可选项；当前值保存在站点配置表，无需数据库迁移 */
    public const SITE_TEMPLATE_OPTIONS = [
        ['id' => 'default', 'name' => '原版', 'description' => '默认整体界面，可继续选择首页模板与登录注册模板', 'author' => 'LoopDeck', 'demoSite' => '-'],
        ['id' => 'ruoyi', 'name' => '若依', 'description' => '登录、首页、用户控制台与站长后台整套切换为若依风格', 'author' => 'RuoYi', 'demoSite' => '-'],
    ];

    public static function currentSiteTemplate(): string
    {
        // 离线测试可能在应用初始化前调用，此时容器尚未绑定 config
        $template = \think\Container::getInstance()->bound('config')
            ? (string)config('sys.site_template', 'default')
            : 'default';
        return in_array($template, array_column(self::SITE_TEMPLATE_OPTIONS, 'id'), true) ? $template : 'default';
    }

    public static function templateSettingsData(array $site = []): array
    {
        $indexTemplates = self::indexTemplateData();
        $loginTemplates = self::loginTemplateData();

        return [
            'num' => count($indexTemplates) + count($loginTemplates),
            'index_data' => $indexTemplates,
            'login_data' => $loginTemplates,
            'site_data' => self::SITE_TEMPLATE_OPTIONS,
            'current_site_template' => self::currentSiteTemplate(),
            'current_index_template' => self::selectedTemplateId(
                $indexTemplates,
                (string)($site['index_template'] ?? 'default')
            ),
            'current_login_template' => self::selectedTemplateId(
                $loginTemplates,
                (string)($site['login_template'] ?? 'default')
            ),
        ];
    }

    public static function indexTemplateData(): array
    {
        return self::templateData('index');
    }

    public static function loginTemplateData(): array
    {
        return self::templateData('login');
    }

    private static function templateData(string $section): array
    {
        if (!in_array($section, ['index', 'login'], true)) {
            return [];
        }

        $directory = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'index'
            . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . $section;
        if (!is_dir($directory)) {
            return [];
        }

        $templates = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $id = $entry->getFilename();
            $metadataPath = $entry->getPathname() . DIRECTORY_SEPARATOR . 'readme.json';
            $entryTemplate = $entry->getPathname() . DIRECTORY_SEPARATOR . ($section === 'index' ? 'index.html' : 'login.html');
            if (!is_file($metadataPath) || !is_file($entryTemplate)) {
                continue;
            }

            $contents = @file_get_contents($metadataPath);
            if (!is_string($contents)) {
                continue;
            }
            try {
                $metadata = json_decode(
                    $contents,
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($metadata)
                || (string)($metadata['id'] ?? '') !== $id
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,19}\z/', $id) !== 1
                || str_contains($id, '..')
            ) {
                continue;
            }

            $templates[] = [
                'id' => $id,
                'name' => trim((string)($metadata['name'] ?? $id)) ?: $id,
                'description' => trim((string)($metadata['description'] ?? '')),
                'author' => trim((string)($metadata['author'] ?? '')),
                'demoSite' => trim((string)($metadata['demoSite'] ?? '')),
                'img' => trim((string)($metadata['img'] ?? '')),
            ];
        }

        usort($templates, static fn(array $left, array $right): int => strnatcasecmp($left['id'], $right['id']));
        return $templates;
    }

    private static function selectedTemplateId(array $templates, string $selected): string
    {
        $ids = array_column($templates, 'id');
        if (in_array($selected, $ids, true)) {
            return $selected;
        }
        if (in_array('default', $ids, true)) {
            return 'default';
        }
        return (string)($ids[0] ?? '');
    }

}
