<?php

declare(strict_types=1);

namespace app\service;

use app\admin\model\Weblist;

final class SiteTheme
{
    /** Paths are relative to the view root selected by SiteTemplate middleware. */
    public static function entry(string $section, string $page): string
    {
        $allowed = ['index' => ['index'], 'login' => ['login', 'reg', 'find', 'reset']];
        if (!isset($allowed[$section]) || !in_array($page, $allowed[$section], true)) {
            throw new \InvalidArgumentException('Unknown entry template');
        }
        if ((string)config('sys.site_template', 'default') === 'ruoyi') {
            return $section . '/' . $page;
        }
        $templates = $section === 'index' ? Weblist::indexTemplateData() : Weblist::loginTemplateData();
        $selected = (string)config('web.' . $section . '_template', 'default');
        if (!in_array($selected, array_column($templates, 'id'), true)) {
            $selected = 'default';
        }
        return $section . '/' . $selected . '/' . $page;
    }
}
