<?php

declare(strict_types=1);

namespace app\service;

final class SiteTheme
{
    /** Paths are relative to the view root selected by SiteTemplate middleware. */
    public static function entry(string $section, string $page): string
    {
        $allowed = ['index' => ['index'], 'login' => ['login', 'reg', 'find', 'reset']];
        if (!isset($allowed[$section]) || !in_array($page, $allowed[$section], true)) {
            throw new \InvalidArgumentException('Unknown entry template');
        }
        return $section . '/' . $page;
    }

    /** Retain configured shared backgrounds after removing original template assets. */
    public static function homeBackground(): string
    {
        $background = (string)config('web.index_bg', '');
        foreach (['1.png', '2.png'] as $name) {
            if ($background === '/static/template/bg/' . $name) {
                return '/static/ruoyi/img/home/' . $name;
            }
        }
        if (str_starts_with($background, '/static/template/')) {
            return '/static/ruoyi/img/login-background-vue3.jpg';
        }
        return $background;
    }

}
