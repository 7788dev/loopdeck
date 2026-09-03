<?php

declare(strict_types=1);

/**
 * Regression tests for the front-end repair pass.
 *
 * Covers: the logout redirect (unparsed template tag in static JS), the card
 * activation typo, password fields rendered as text, the duplicate modal id
 * inside the qrcode list loop, hidden-input name collisions, the missing
 * FontAwesome stylesheet in the admin layout, the sidebar navigation nesting,
 * and hardcoded third-party tracking left in shipped templates.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

function frontEndCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);

// --- Static JS: logout must not carry an unparsed template tag ----------

$appJs = file_get_contents($root . '/public/static/js/app.min.js');
frontEndCheck(is_string($appJs), 'Unable to inspect app.min.js');
frontEndCheck(
    !str_contains($appJs, '{:url('),
    'app.min.js still embeds an unparsed ThinkPHP template tag'
);
frontEndCheck(
    !str_contains($appJs, 'checkCookie'),
    'the dead English checkCookie helper is still shipped'
);
frontEndCheck(
    !str_contains($appJs, '网络链接错误'),
    'the "网络链接错误" typo (链接→连接) is still present'
);

// --- Shop card activation typo ------------------------------------------

$cardView = file_get_contents($root . '/app/index/view/console/shop/card.html');
frontEndCheck(is_string($cardView), 'Unable to inspect the card activation view');
frontEndCheck(
    !str_contains($cardView, 'reutrn'),
    'the "reutrn" typo still breaks empty card submission'
);

// --- Password fields -----------------------------------------------------

$passwordViews = [
    '/app/index/view/console/user/profile.html',
    '/app/index/view/login/reset.html',
    '/app/index/view/login/default/reset.html',
    '/app/index/view/login/Brevity/reset.html',
];
foreach ($passwordViews as $view) {
    $source = file_get_contents($root . $view);
    frontEndCheck(is_string($source), 'Unable to inspect ' . $view);
    $masked = preg_replace('/type="text"/', '', $source) ?? '';
    frontEndCheck(
        !preg_match('/(id|name)="(?:password|repass|outpass)"[^>]*type="text"|type="text"[^>]*(id|name)="(?:password|repass|outpass)"/', $source),
        $view . ' still renders a password field as plain text'
    );
}

// --- Qrcode create: hidden input name collisions ------------------------

$qrCreate = file_get_contents($root . '/app/index/view/console/qrcode/create.html');
frontEndCheck(is_string($qrCreate), 'Unable to inspect the qrcode create view');
frontEndCheck(
    substr_count($qrCreate, 'name="alipay_url"') === 1,
    'the three hidden url inputs still share the alipay_url name'
);
frontEndCheck(
    !str_contains($qrCreate, 'ajax_dowmloadImg'),
    'the "dowmloadImg" typo is still present'
);

// --- Qrcode list: one modal, not one per row ----------------------------

$qrList = file_get_contents($root . '/app/index/view/console/qrcode/list.html');
frontEndCheck(is_string($qrList), 'Unable to inspect the qrcode list view');
$modalPosition = strpos($qrList, 'id="address"');
$loopEnd = strpos($qrList, '{/foreach}');
frontEndCheck(
    $modalPosition !== false && $loopEnd !== false && $modalPosition > $loopEnd,
    'the address modal is still rendered inside the list loop'
);
frontEndCheck(
    !str_contains($qrList, 'console.log(user_id)'),
    'the console.log debug residue is still shipped'
);

// --- Admin layout must ship FontAwesome ---------------------------------

$adminLayout = file_get_contents($root . '/app/admin/view/common/layout.html');
frontEndCheck(is_string($adminLayout), 'Unable to inspect the admin layout');
frontEndCheck(
    str_contains($adminLayout, 'fontawesome'),
    'the admin layout still loads no FontAwesome stylesheet for its fa icons'
);
frontEndCheck(
    str_contains($adminLayout, '<html lang="zh-CN">'),
    'the admin layout still declares lang="en" on a Chinese UI'
);

// --- Console sidebar: no <li> nested inside <li> ------------------------

$head = file_get_contents($root . '/app/index/view/console/head.html');
frontEndCheck(is_string($head), 'Unable to inspect the console navigation');
// The nav list items must not nest another li directly inside a li; every li
// opened in the sidebar region has to be closed there.
$sidebar = substr($head, 0, strpos($head, '</nav>') ?: strlen($head));
$opens = preg_match_all('/<li\b/', $sidebar) ?: 0;
$closes = preg_match_all('/<\/li>/', $sidebar) ?: 0;
frontEndCheck(
    $opens === $closes,
    "sidebar li tags do not balance ({$opens} opens vs {$closes} closes)"
);
frontEndCheck(
    !str_contains($head, '𝙑𝙄𝙋'),
    'the sidebar still uses mathematical bold italic characters for the VIP label'
);
frontEndCheck(
    !str_contains($head, 'data-pjax href="javascript:'),
    'a javascript: link is still marked data-pjax and can no longer fire'
);

// --- Index skins: no hardcoded third-party tracking ---------------------

$skins = glob($root . '/app/index/view/index/*/index.html') ?: [];
$skins[] = $root . '/app/index/view/index/index.html';
foreach ($skins as $skin) {
    $source = file_get_contents($skin);
    frontEndCheck(is_string($source), 'Unable to inspect ' . $skin);
    frontEndCheck(
        !str_contains($source, 'hm.baidu.com') && !str_contains($source, 'sozz') && !str_contains($source, 'zhanzhang'),
        basename(dirname($skin)) . '/index.html still hardcodes third-party tracking'
    );
}

// --- Layout: scripts belong inside <body> ------------------------------

$layout = file_get_contents($root . '/app/index/view/common/layout.html');
frontEndCheck(is_string($layout), 'Unable to inspect the index layout');
$bodyClose = strrpos($layout, '</body>');
$htmlClose = strrpos($layout, '</html>');
$lastScript = strrpos($layout, '<script src="/static/js/layer.js">');
frontEndCheck(
    $bodyClose !== false && $lastScript !== false && $lastScript < $bodyClose && $bodyClose < $htmlClose,
    'layout scripts are still emitted after </body> or </html>'
);
frontEndCheck(
    substr_count($layout, '</head>') === 1,
    'the layout still closes </head> more than once'
);

// --- Card naming: a 6-month card must not be sold as 季 -----------------

$vipView = file_get_contents($root . '/app/index/view/console/shop/vip.html');
frontEndCheck(is_string($vipView), 'Unable to inspect the vip shop view');
frontEndCheck(
    !preg_match('/包季VIP<\/h3>\s*<\/div>\s*<div class="block-content bg-body-light">\s*<div class="h1[^"]*">[^<]*<\/div>\s*<div class="fw-medium text-muted mb-4">每 6 月/', $vipView),
    'a 6-month plan is still labelled 包季 (quarterly)'
);
$siteView = file_get_contents($root . '/app/index/view/console/shop/site.html');
frontEndCheck(
    !preg_match('/包季分站<\/h3>\s*<\/div>\s*<div class="block-content bg-body-light">\s*<div class="h1[^"]*">[^<]*<\/div>\s*<div class="fw-medium text-muted mb-4">每 6 月/', $siteView),
    'a 6-month sub-site plan is still labelled 包季 (quarterly)'
);

echo "Front-end repair tests passed\n";
