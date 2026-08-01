<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

use app\admin\model\Weblist;
use think\facade\Config;

function adminTemplateCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$indexTemplates = Weblist::indexTemplateData();
$loginTemplates = Weblist::loginTemplateData();

adminTemplateCheck(count($indexTemplates) === 6, 'Unexpected homepage template count');
adminTemplateCheck(count($loginTemplates) === 3, 'Unexpected login template count');
adminTemplateCheck(Weblist::templateCount() === 9, 'Template count includes non-template files');
adminTemplateCheck(
    in_array('shanhe2.0', array_column($indexTemplates, 'id'), true),
    'Template IDs containing a safe dot were rejected'
);

foreach (array_merge($indexTemplates, $loginTemplates) as $template) {
    foreach (['id', 'name', 'description', 'author', 'demoSite', 'img'] as $field) {
        adminTemplateCheck(isset($template[$field]) && is_string($template[$field]), 'Invalid template metadata');
    }
    adminTemplateCheck(
        $template['img'] !== '' && is_file($root . '/public' . $template['img']),
        'Template preview image is missing: ' . $template['id']
    );
}

$settings = Weblist::templateSettingsData([
    'index_template' => 'shanhe2.0',
    'login_template' => 'onebox',
]);
adminTemplateCheck($settings['num'] === 9, 'Template page received the wrong total');
adminTemplateCheck($settings['current_index_template'] === 'shanhe2.0', 'Homepage selection was lost');
adminTemplateCheck($settings['current_login_template'] === 'onebox', 'Login selection was lost');

$fallback = Weblist::templateSettingsData([
    'index_template' => '../invalid',
    'login_template' => '../invalid',
]);
adminTemplateCheck($fallback['current_index_template'] === 'default', 'Invalid homepage selection did not fall back');
adminTemplateCheck($fallback['current_login_template'] === 'default', 'Invalid login selection did not fall back');

$controller = file_get_contents($root . '/app/admin/controller/System.php');
$view = file_get_contents($root . '/app/admin/view/system/set/template.html');
adminTemplateCheck(
    is_string($controller) && str_contains($controller, "Weblist::templateSettingsData((array)config('web'))"),
    'Template page variables are not assigned by the controller'
);
adminTemplateCheck(
    is_string($view) && !str_contains($view, 'template_start') && !str_contains($view, '$start'),
    'Removed template initialization flow is still referenced'
);

if (!defined('PJAX')) {
    define('PJAX', true);
}
if (!defined('WEB_ID')) {
    define('WEB_ID', 1);
}
$app = new think\App($root);
$app->initialize();
Config::set(['webname' => 'LoopDeck', 'title' => 'Test'], 'web');
$cachePath = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR . 'loopdeck-admin-template-test-'
    . bin2hex(random_bytes(6))
    . DIRECTORY_SEPARATOR;
adminTemplateCheck(mkdir($cachePath, 0700, true), 'Unable to create template test cache');

$engine = new think\Template([
    'view_path' => $root . '/app/admin/view/',
    'cache_path' => $cachePath,
]);
$settings['webTitle'] = 'Template settings';
ob_start();
try {
    $engine->fetch('system/set/template', $settings);
    $html = (string)ob_get_contents();
} finally {
    ob_end_clean();
}

adminTemplateCheck(str_contains($html, 'id="template-form"'), 'Template settings page did not render');
adminTemplateCheck(substr_count($html, 'data-template-id=') === 9, 'Rendered template cards are incomplete');
adminTemplateCheck(substr_count($html, 'aria-pressed="true"') === 2, 'Current templates are not marked');

echo "Admin template settings tests passed\n";
