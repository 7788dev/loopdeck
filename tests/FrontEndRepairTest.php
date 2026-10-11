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
    str_contains($adminLayout, 'font-awesome'),
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

// --- Admin dialogs: x.ajax already decodes JSON, dialogs must not re-parse --

foreach (['admin_noticesList_datatables.js', 'admin_tasklist_datatables.js', 'admin_usersList_datatables.js'] as $dataTableScript) {
    $source = file_get_contents($root . '/public/static/js/' . $dataTableScript);
    frontEndCheck(is_string($source), 'Unable to inspect ' . $dataTableScript);
    frontEndCheck(
        !str_contains($source, 'JSON.parse('),
        $dataTableScript . ' still re-parses the already-decoded x.ajax payload'
    );
}

$noticesScript = file_get_contents($root . '/public/static/js/admin_noticesList_datatables.js');
frontEndCheck(is_string($noticesScript), 'Unable to inspect the notices script');
frontEndCheck(
    str_contains($noticesScript, 'var noticeEditor = null;') && str_contains($noticesScript, 'noticeEditor.getHtml()'),
    'The notice save path no longer reads the editor value directly'
);
frontEndCheck(
    !str_contains($noticesScript, 'content = editor.getHtml()'),
    'The notice editor still writes through an implicit global `content`'
);
frontEndCheck(
    str_contains($noticesScript, 'x.escapeHtml(cutstr('),
    'The notice list still injects cutstr output as unescaped HTML'
);
frontEndCheck(
    !str_contains($noticesScript, 'id=\\"sort\\"'),
    'The notices table still renders duplicate id="sort" attributes'
);

// --- Dashboard: the notice modal route and the slider autoplay timer --------

$dashboard = file_get_contents($root . '/app/admin/view/system/index.html');
frontEndCheck(is_string($dashboard), 'Unable to inspect the admin dashboard');
frontEndCheck(
    !str_contains($dashboard, 'info/site_notice'),
    'The dashboard notice modal still calls the retired site_notice route'
);
frontEndCheck(
    str_contains($dashboard, "pjax:start.loopdeck-slick") && str_contains($dashboard, "slick('unslick')"),
    'The dashboard slider autoplay still leaks its interval across PJAX navigation'
);

// --- PJAX timer hygiene on the account binding pages ------------------------

$neteaseAdd = file_get_contents($root . '/app/index/view/console/netease/add.html');
frontEndCheck(
    str_contains($neteaseAdd, 'document.body.contains($qrImg[0])'),
    'The netease verify poll still runs after PJAX leaves the page'
);
$bilibiliAdd = file_get_contents($root . '/app/index/view/console/bilibili/add.html');
frontEndCheck(
    str_contains($bilibiliAdd, 'document.body.contains(button[0])'),
    'The SMS countdown still runs after PJAX leaves the page'
);

// --- Shared admin scripts carry the app_version cache stamp ------------------

foreach ([
    'system/data/notices.html' => 'admin_noticesList_datatables.js',
    'system/data/accounts.html' => 'admin_accountList_datatables.js',
    'system/task/list.html' => 'admin_tasklist_datatables.js',
] as $view => $script) {
    $source = file_get_contents($root . '/app/admin/view/' . $view);
    frontEndCheck(is_string($source), 'Unable to inspect ' . $view);
    frontEndCheck(
        str_contains($source, $script . '?v={:app_version()}'),
        $view . ' loads ' . $script . ' without an app_version cache stamp'
    );
}

// --- QR alert injection, admin notice add dialog, users dialog pickers -----

frontEndCheck(
    substr_count($neteaseAdd, "qr-alert').text(data.message)") === 3
        && !str_contains($neteaseAdd, "qr-alert').html(data.message)"),
    'The netease QR alert still injects upstream messages as HTML'
);
frontEndCheck(
    str_contains($neteaseAdd, "qr-alert').html('当前网络环境存在风险"),
    'The static verify hint lost its trusted markup'
);

$noticesView = file_get_contents($root . '/app/admin/view/system/data/notices.html');
frontEndCheck(is_string($noticesView), 'Unable to inspect the notices view');
frontEndCheck(
    preg_match('/yes:\s*function\s*\(index,\s*dom\)\s*\{\s*var content = noticeContent\(\);/', $noticesView) === 1,
    'The admin notice add dialog still reads an undefined `content` variable'
);
frontEndCheck(
    str_contains($noticesView, 'noticeEditor = loadwangEditor();'),
    'The admin notice add dialog still discards the editor instance'
);

$usersScript = file_get_contents($root . '/public/static/js/admin_usersList_datatables.js');
frontEndCheck(is_string($usersScript), 'Unable to inspect the users dialog script');
frontEndCheck(
    !str_contains($usersScript, 'Codebase'),
    'The user dialog still depends on the absent Codebase helper'
);
frontEndCheck(
    str_contains($usersScript, '#info-form .js-flatpickr")'),
    'The user dialog date pickers are not initialized directly'
);
frontEndCheck(
    substr_count($usersScript, 'slice(0, 10)') === 2,
    'The user dialog still feeds raw datetime values into a date-only picker'
);

// --- Stale-dialog races, editor height, QR base64 injection --------------

// A closed dialog's editor must never feed the next dialog's save: both the
// edit and add flows drop the module editor before opening a new dialog.
$functionResetsEditor = static function (string $source, string $function): bool {
    if (preg_match('/function ' . $function . '\([^)]*\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return false;
    }
    $bodyStart = $m[0][1] + strlen($m[0][0]);
    $openAt = strpos($source, 'layer.open({', $bodyStart);
    $resetAt = strpos($source, 'noticeEditor = null;', $bodyStart);
    return $openAt !== false && $resetAt !== false && $resetAt < $openAt;
};
frontEndCheck(
    $functionResetsEditor($noticesScript, 'ajax_edit_notice'),
    'The notice edit dialog still reuses the previous dialog\'s editor'
);
frontEndCheck(
    $functionResetsEditor($noticesScript, 'ajax_add_notice'),
    'The notice add dialog still reuses the previous dialog\'s editor'
);
frontEndCheck(
    $functionResetsEditor($noticesView, 'ajax_add_notice2'),
    'The admin notice add dialog still reuses the previous dialog\'s editor'
);
frontEndCheck(
    str_contains($noticesView, '<div id="editor-container" style="height: 250px;">'),
    'The admin notice add dialog editor renders with a collapsed height'
);

// A late response for a closed user dialog must not fill the newly opened one.
frontEndCheck(
    str_contains($usersScript, 'var userEditRequestToken = 0;')
        && str_contains($usersScript, 'if (requestToken !== userEditRequestToken) {'),
    'The user edit dialog still fills its inputs from a stale info response'
);

// Upstream QR payloads are concatenated into an img-src HTML sink: the
// base64 payload must be escaped like every other upstream value.
$qrSinkEscape = static function (string $source): bool {
    return str_contains($source, "x.escapeHtml(data.data.qrimg)")
        && str_contains($source, 'x.escapeHtml(d.verify_qrimg)');
};
frontEndCheck(
    $qrSinkEscape($neteaseAdd),
    'The netease QR image still injects the upstream payload unescaped'
);
frontEndCheck(
    str_contains($bilibiliAdd, 'x.escapeHtml(data.data.qrimg)'),
    'The bilibili QR image still injects the upstream payload unescaped'
);

// The admin notice detail endpoint filters the rich text through the same
// sanitizer the user dashboard uses (the modal renders content as HTML).
$adminAjax = file_get_contents($root . '/app/admin/controller/Ajax.php');
frontEndCheck(
    preg_match("/case 'notice':.*?\\\$record\['content'\] = safe_html/s", $adminAjax) === 1,
    'The admin notice detail endpoint still returns unfiltered rich text'
);

$templateDivBalance = static function (string $html): int {
    $stripped = preg_replace('/\{[^{}]*\}/', '', $html) ?? '';
    return substr_count($stripped, '<div') - substr_count($stripped, '</div>');
};
$bilibiliList = file_get_contents($root . '/app/index/view/console/bilibili/list.html');
frontEndCheck(is_string($bilibiliList), 'Unable to inspect the bilibili account list');
frontEndCheck(
    $templateDivBalance($bilibiliList) === 0,
    'The bilibili account list still ships an unbalanced <div> structure'
);
$neteaseList = file_get_contents($root . '/app/index/view/console/netease/list.html');
frontEndCheck(
    $templateDivBalance((string)$neteaseList) === 0,
    'The netease account list reference template is unbalanced'
);

echo "Front-end repair tests passed\n";
