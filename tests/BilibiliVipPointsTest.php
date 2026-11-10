<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use bilibili\BiliHelper;
use bilibili\sdk\Client;
use bilibili\sdk\TransportInterface;
use bilibili\sdk\VipPoints;

function pointsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** The public task-center schema, with deliberately unrelated purchase/mobile tasks. */
function pointsTaskFixture(array $states = [], bool $signed = false, int $expValue = 3): array
{
    $items = [];
    foreach (array_replace([
        'bonus' => 1, 'privilege' => 1, 'dress-view' => 1,
        'ogvwatchnew' => 0, 'tvodbuy' => 0, 'vipmallview' => 1,
    ], $states) as $code => $state) {
        $items[] = [
            'task_code' => $code,
            'state' => $state,
            'complete_times' => $state === 3 ? 1 : 0,
            'max_times' => 1,
            'link' => 'https://fixture.invalid/untrusted-task/' . $code,
        ];
    }
    return [
        'code' => 0,
        'data' => [
            'vip_info' => ['type' => 2, 'status' => 1],
            'point_info' => ['point' => 317],
            'current_ts' => time(),
            'task_info' => [
                'exp_value' => $expValue,
                'score_month' => 0,
                'score_limit' => 10000,
                'modules' => [['common_task_item' => $items]],
                'sing_task_item' => [
                    'histories' => [[
                        'is_today' => true, 'day' => date('Y-m-d'), 'signed' => $signed, 'score' => 5,
                    ]],
                    'count' => $signed ? 1 : 0,
                    'base_score' => 5,
                ],
            ],
        ],
    ];
}

final class PointsTransport implements TransportInterface
{
    public array $requests = [];
    public array $overrides = [];
    public array $unexpected = [];
    public bool $settleOnSubmit = true;

    public function __construct(public array $tasks) {}

    public function request(string $method, string $url, array $options = []): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $this->requests[] = compact('method', 'url', 'path', 'options');
        if (!empty($this->overrides[$path])) {
            $payload = array_shift($this->overrides[$path]);
            if ($payload instanceof Throwable) {
                throw $payload;
            }
        } elseif ($path === '/x/vip_point/task/combine') {
            $payload = $this->tasks;
        } elseif ($path === '/x/vip/vip_center/sign_in/three_days_sign') {
            $payload = [
                'code' => 0,
                'data' => [
                    'three_day_sign' => [
                        'signed' => $this->tasks['data']['task_info']['sing_task_item']['histories'][0]['signed'],
                        'count' => $this->tasks['data']['task_info']['sing_task_item']['count'],
                        'exp_value' => $this->tasks['data']['task_info']['exp_value'],
                    ],
                    'big_point' => ['point' => $this->tasks['data']['point_info']['point']],
                ],
            ];
        } elseif ($path === '/x/web-interface/nav') {
            $payload = ['code' => 0, 'data' => ['isLogin' => true, 'mid' => 42, 'uname' => '积分测试账号']];
        } elseif (in_array($path, ['/pgc/activity/score/task/sign2', '/pgc/activity/score/task/sign'], true)) {
            if ($this->settleOnSubmit && !$this->tasks['data']['task_info']['sing_task_item']['histories'][0]['signed']) {
                $this->tasks['data']['task_info']['sing_task_item']['histories'][0]['signed'] = true;
                $this->tasks['data']['task_info']['sing_task_item']['count'] = 1;
                $this->tasks['data']['point_info']['point'] += 5;
            }
            $payload = ['code' => 0, 'data' => ['point' => 999999]];
        } elseif ($path === '/pgc/activity/score/task/complete/v2') {
            $taskCode = $options['form_params']['taskCode'] ?? '';
            if ($this->settleOnSubmit) {
                foreach ($this->tasks['data']['task_info']['modules'] as &$module) {
                    foreach ($module['common_task_item'] as &$item) {
                        if ($item['task_code'] === $taskCode && $item['state'] === 1) {
                            $item['state'] = 3;
                            $item['complete_times'] = 1;
                            $this->tasks['data']['point_info']['point'] += 7;
                        }
                    }
                    unset($item);
                }
                unset($module);
            }
            $payload = ['code' => 0, 'data' => ['point' => 999999]];
        } else {
            $this->unexpected[] = $path;
            throw new RuntimeException('Unexpected points fixture request: ' . $path);
        }
        return [
            'status' => 200, 'headers' => [],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'header' => '', 'set_cookie' => [],
        ];
    }

    public function matching(string $path): array
    {
        return array_values(array_filter($this->requests, static fn(array $request): bool => $request['path'] === $path));
    }

    public function posts(): array
    {
        return array_values(array_filter($this->requests, static fn(array $request): bool => $request['method'] === 'POST'));
    }

    public function setTaskState(string $code, int $state): void
    {
        foreach ($this->tasks['data']['task_info']['modules'] as &$module) {
            foreach ($module['common_task_item'] as &$item) {
                if ($item['task_code'] === $code) {
                    $item['state'] = $state;
                    $item['complete_times'] = $state === 3 ? 1 : 0;
                }
            }
            unset($item);
        }
        unset($module);
    }
}

function pointsClient(PointsTransport $transport): Client
{
    return new Client([
        'DedeUserID' => '42', 'DedeUserID__ckMd5' => 'fixture-md5',
        'SESSDATA' => 'fixture-session', 'bili_jct' => 'fixture-csrf',
        'buvid3' => 'fixture-buvid', 'buvid4' => 'fixture-buvid4',
        'b_nut' => '1700000000', 'bili_ticket' => 'fixture-ticket',
    ], [], $transport);
}

function pointsSubmitted(array $result, array $expected, string $message): void
{
    $actual = $result['points_submitted'] ?? [];
    pointsCheck(is_array($actual) && array_is_list($actual), 'Submitted points actions must be a list');
    sort($actual);
    sort($expected);
    pointsCheck($actual === $expected, $message);
}

// Exercise the real SDK request construction, not source-string assertions.
$sdkTransport = new PointsTransport(pointsTaskFixture());
$sdk = pointsClient($sdkTransport);
$sdk->vipPointTasks();
$sdk->vipPointSignState();
$sdk->vipPointSign(true);
$sdk->vipPointSign(false);
foreach (['bonus', 'privilege', 'dress-view'] as $code) {
    $sdk->completeVipPointTask($code);
}
pointsCheck(count($sdkTransport->requests) === 7, 'Points SDK performed unexpected prerequisite requests');
foreach (['/x/vip_point/task/combine', '/x/vip/vip_center/sign_in/three_days_sign'] as $path) {
    pointsCheck($sdkTransport->matching($path)[0]['method'] === 'GET', 'Points read endpoint must use GET');
}
$newSign = $sdkTransport->matching('/pgc/activity/score/task/sign2')[0];
pointsCheck($newSign['method'] === 'POST' && isset($newSign['options']['json']) && !isset($newSign['options']['form_params']),
    'Three-day sign must send the desktop JSON protocol');
pointsCheck((int)($newSign['options']['json']['t'] ?? 0) > 0, 'Three-day sign timestamp is missing');
$oldSign = $sdkTransport->matching('/pgc/activity/score/task/sign')[0];
pointsCheck($oldSign['method'] === 'POST' && isset($oldSign['options']['form_params']) && !isset($oldSign['options']['json']),
    'Legacy sign must keep its form protocol');
foreach ($sdkTransport->requests as $request) {
    pointsCheck(str_starts_with($request['url'], 'https://api.bilibili.com/'), 'Points SDK used an unrelated host');
    pointsCheck(str_contains($request['options']['headers']['Cookie'] ?? '', 'SESSDATA=fixture-session'), 'Points SDK dropped the account cookie');
    $query = json_encode($request['options']['query'] ?? []);
    pointsCheck(!str_contains($request['url'] . $query, 'fixture-csrf') && !str_contains($request['url'] . $query, 'fixture-session'),
        'Points SDK put account credentials into a URL');
    if ($request['method'] === 'POST') {
        $body = $request['options']['json'] ?? $request['options']['form_params'] ?? [];
        pointsCheck(($body['csrf'] ?? '') === 'fixture-csrf' && ($body['buvid'] ?? '') === 'fixture-buvid',
            'Points mutation lost CSRF or device identity');
    }
}
$sdkCodes = array_map(static fn(array $request): string => $request['options']['form_params']['taskCode'] ?? '',
    $sdkTransport->matching('/pgc/activity/score/task/complete/v2'));
pointsCheck($sdkCodes === ['bonus', 'privilege', 'dress-view'], 'Browse task completion must use the current form taskCode contract');
foreach (['ogvwatchnew', 'tvodbuy', 'vipmallview', 'unknown', '', 'https://fixture.invalid/task'] as $code) {
    $before = count($sdkTransport->requests);
    try {
        $rejected = $sdk->completeVipPointTask($code);
        pointsCheck(($rejected['code'] ?? 0) !== 0, 'Unsupported task code was accepted');
    } catch (InvalidArgumentException $exception) {
        // Both local exceptions and structured SDK errors are valid rejections.
    }
    pointsCheck(count($sdkTransport->requests) === $before, 'Unsupported task code reached the transport');
}

$completeStates = ['bonus' => 3, 'privilege' => 3, 'dress-view' => 3];
$transport = new PointsTransport(pointsTaskFixture());
$result = (new VipPoints(pointsClient($transport)))->run();
pointsCheck($result['code'] === 1 && empty($result['pending_verification']), 'Confirmed points completion did not finish successfully');
pointsCheck(count($transport->matching('/x/vip_point/task/combine')) >= 2, 'Points workflow trusted POST acceptance without reading back task completion');
pointsCheck(count($transport->matching('/pgc/activity/score/task/sign2')) === 1 && $transport->matching('/pgc/activity/score/task/sign') === [],
    'New-center workflow did not select sign2 exclusively');
$codes = array_map(static fn(array $request): string => $request['options']['form_params']['taskCode'],
    $transport->matching('/pgc/activity/score/task/complete/v2'));
sort($codes);
pointsCheck($codes === ['bonus', 'dress-view', 'privilege'], 'Workflow executed purchase, mobile-only, or unknown task entries');
pointsCheck($transport->unexpected === [], 'Workflow followed an untrusted task URL');
pointsCheck(str_contains($result['message'], '积分余额 343') && str_contains($result['message'], '本次增加 26')
    && !str_contains($result['message'], '999999'), 'Points copy did not use the actual read-back balance and difference');

$already = new PointsTransport(pointsTaskFixture($completeStates, true));
$alreadyResult = (new VipPoints(pointsClient($already)))->run();
pointsCheck($alreadyResult['code'] === 1 && $already->posts() === [], 'Already completed points actions were posted again');
$legacy = new PointsTransport(pointsTaskFixture($completeStates, false, 1));
pointsCheck((new VipPoints(pointsClient($legacy)))->run()['code'] === 1, 'Legacy sign settlement did not finish');
pointsCheck(count($legacy->matching('/pgc/activity/score/task/sign')) === 1 && $legacy->matching('/pgc/activity/score/task/sign2') === [],
    'Legacy sign was silently upgraded to the incompatible JSON endpoint');

// HTTP success is not settlement. A new workflow instance must retain the submitted set.
$delayed = new PointsTransport(pointsTaskFixture());
$delayed->settleOnSubmit = false;
$delayedClient = pointsClient($delayed);
$pending = (new VipPoints($delayedClient))->run();
pointsCheck($pending['code'] === 0 && !empty($pending['pending_verification']), 'Unsettled points were reported as success');
pointsSubmitted($pending, ['sign', 'bonus', 'privilege', 'dress-view'], 'Pending points lost submitted operations');
$postCount = count($delayed->posts());
$pendingAgain = (new VipPoints($delayedClient, ['verification_only' => true, 'points_submitted' => $pending['points_submitted']]))->run();
pointsCheck($pendingAgain['code'] === 0 && !empty($pendingAgain['pending_verification']), 'Unsettled verification ended prematurely');
pointsCheck(count($delayed->posts()) === $postCount, 'Verification resubmitted an already attempted points action');
pointsSubmitted($pendingAgain, ['sign', 'bonus', 'privilege', 'dress-view'], 'Verification dropped earlier submitted operations');
foreach (['/x/vip_point/task/combine', '/x/vip/vip_center/sign_in/three_days_sign'] as $readPath) {
    $delayed->overrides[$readPath] = [new RuntimeException('fixture verification read temporarily unavailable')];
    $readFailure = (new VipPoints($delayedClient, ['verification_only' => true, 'points_submitted' => $pendingAgain['points_submitted']]))->run();
    pointsCheck($readFailure['code'] === 0 && !empty($readFailure['pending_verification']),
        'A temporary verification read failure discarded the pending settlement');
    pointsSubmitted($readFailure, ['sign', 'bonus', 'privilege', 'dress-view'], 'A temporary read failure forgot prior submissions');
    pointsCheck(count($delayed->posts()) === $postCount, 'A failed verification read triggered another mutation');
}
$delayed->tasks = pointsTaskFixture($completeStates, true);
$settled = (new VipPoints($delayedClient, ['verification_only' => true, 'points_submitted' => $pendingAgain['points_submitted']]))->run();
pointsCheck($settled['code'] === 1 && empty($settled['pending_verification']) && count($delayed->posts()) === $postCount,
    'Read-only verification did not recognize later settlement');

// Eligibility may arrive after the initial pass; only each already submitted item is read-only.
$partial = new PointsTransport(pointsTaskFixture(['privilege' => 0, 'dress-view' => 0], true));
$partial->settleOnSubmit = false;
$partialClient = pointsClient($partial);
$first = (new VipPoints($partialClient))->run();
pointsSubmitted($first, ['bonus'], 'Unready tasks were submitted before becoming actionable');
pointsCheck(!empty($first['pending_verification']), 'Submitted but unsettled browse task lost its verification');
$partial->setTaskState('privilege', 1);
$partial->setTaskState('dress-view', 1);
$second = (new VipPoints($partialClient, ['verification_only' => true, 'points_submitted' => $first['points_submitted']]))->run();
$partialCodes = array_map(static fn(array $request): string => $request['options']['form_params']['taskCode'],
    $partial->matching('/pgc/activity/score/task/complete/v2'));
sort($partialCodes);
pointsCheck($partialCodes === ['bonus', 'dress-view', 'privilege'], 'Verification blocked a first submission or repeated a previous one');
pointsSubmitted($second, ['bonus', 'privilege', 'dress-view'], 'Late eligibility did not merge the submitted set');

foreach (['/pgc/activity/score/task/sign2', '/pgc/activity/score/task/complete/v2'] as $lostPath) {
    $isSign = str_ends_with($lostPath, '/sign2');
    $lost = new PointsTransport(pointsTaskFixture($isSign ? $completeStates : array_replace($completeStates, ['bonus' => 1]), !$isSign));
    $lost->overrides[$lostPath] = [new RuntimeException('fixture connection closed after request')];
    $lostClient = pointsClient($lost);
    $uncertain = (new VipPoints($lostClient))->run();
    pointsCheck($uncertain['code'] === 0 && !empty($uncertain['pending_verification']), 'A lost mutation response was treated as safely retryable or successful');
    pointsSubmitted($uncertain, [$isSign ? 'sign' : 'bonus'], 'A lost response forgot its potentially submitted action');
    $lostCount = count($lost->posts());
    (new VipPoints($lostClient, ['verification_only' => true, 'points_submitted' => $uncertain['points_submitted']]))->run();
    pointsCheck(count($lost->posts()) === $lostCount, 'Uncertain mutation was sent again during verification');
}

// A failed device preflight performed no mutation. Its action must retain the
// right to make its first POST after the read-only prerequisite recovers.
$deviceDelayed = new PointsTransport(pointsTaskFixture());
$deviceDelayed->overrides['/x/frontend/finger/spi'] = [
    new RuntimeException('fixture device preflight temporarily unavailable'),
    ['code' => 0, 'data' => ['b_3' => 'recovered-device', 'b_4' => 'recovered-device-4']],
];
$deviceClient = new Client(['DedeUserID' => '42', 'SESSDATA' => 'fixture-session', 'bili_jct' => 'fixture-csrf'], [], $deviceDelayed);
$deviceFirst = (new VipPoints($deviceClient))->run();
pointsCheck($deviceFirst['code'] === 0 && !empty($deviceFirst['pending_verification']), 'Temporary device prerequisite failure did not stay pending');
pointsSubmitted($deviceFirst, ['bonus', 'privilege', 'dress-view'], 'A failed device GET marked an unsent sign mutation as submitted');
pointsCheck($deviceDelayed->matching('/pgc/activity/score/task/sign2') === [], 'Device failure unexpectedly sent the sign mutation');
$deviceSecond = (new VipPoints($deviceClient, ['verification_only' => true, 'points_submitted' => $deviceFirst['points_submitted']]))->run();
pointsCheck($deviceSecond['code'] === 1 && empty($deviceSecond['pending_verification']), 'Recovered device prerequisite never allowed the first sign submission');
pointsCheck(count($deviceDelayed->matching('/pgc/activity/score/task/sign2')) === 1
    && count($deviceDelayed->matching('/pgc/activity/score/task/complete/v2')) === 3,
    'Recovered device flow missed first sign or repeated settled browse mutations');

foreach (['sign', 'bonus'] as $deviceAction) {
    $deviceFailure = new PointsTransport(pointsTaskFixture());
    $deviceFailure->overrides['/x/frontend/finger/spi'] = [new RuntimeException('fixture fingerprint unavailable')];
    $deviceSdk = new Client(['DedeUserID' => '42', 'SESSDATA' => 'fixture-session', 'bili_jct' => 'fixture-csrf'], [], $deviceFailure);
    $deviceResponse = $deviceAction === 'sign' ? $deviceSdk->vipPointSign(true) : $deviceSdk->completeVipPointTask($deviceAction);
    pointsCheck(($deviceResponse['request_submitted'] ?? null) === false && $deviceFailure->posts() === [],
        'Points SDK failed to distinguish an unsent mutation from a lost POST response');
}

$deviceOnlySign = new PointsTransport(pointsTaskFixture($completeStates, false));
$deviceOnlySign->overrides['/x/frontend/finger/spi'] = [
    new RuntimeException('fixture sign prerequisite unavailable'),
    ['code' => 0, 'data' => ['b_3' => 'sign-device', 'b_4' => 'sign-device-4']],
];
$deviceOnlySignClient = new Client(['DedeUserID' => '42', 'SESSDATA' => 'fixture-session', 'bili_jct' => 'fixture-csrf'], [], $deviceOnlySign);
$onlySignPending = (new VipPoints($deviceOnlySignClient))->run();
pointsSubmitted($onlySignPending, [], 'Failed sign prerequisite invented a submitted action when no POST was sent');
pointsCheck(!empty($onlySignPending['pending_verification']), 'A safely retryable device read was dropped because no mutations had been submitted');
$onlySignDone = (new VipPoints($deviceOnlySignClient, ['verification_only' => true, 'points_submitted' => []]))->run();
pointsCheck($onlySignDone['code'] === 1 && count($deviceOnlySign->matching('/pgc/activity/score/task/sign2')) === 1,
    'Read-only device recovery did not permit its first real sign submission');

foreach (['/x/web-interface/nav', '/x/vip_point/task/combine', '/x/vip/vip_center/sign_in/three_days_sign'] as $preflightReadPath) {
    $scheduledDevice = new PointsTransport(pointsTaskFixture($completeStates, false));
    $scheduledDevice->overrides['/x/frontend/finger/spi'] = [new RuntimeException('fixture initial device prerequisite failed')];
    $scheduledDeviceClient = new Client(['DedeUserID' => '42', 'SESSDATA' => 'fixture-session', 'bili_jct' => 'fixture-csrf'], [], $scheduledDevice);
    $deviceClock = strtotime('2026-11-11 09:00:00');
    $deviceExecutor = new app\service\BilibiliTaskExecutor(
        static fn(array $account, array $config): BiliHelper => new BiliHelper(
            $account['mid'], $account['mid_md5'], $account['token'], $account['csrf'], '', $config, $scheduledDeviceClient
        ),
        static function () use (&$deviceClock): int { return $deviceClock; }
    );
    $deviceAccount = ['mid' => '42', 'mid_md5' => 'fixture-md5', 'token' => 'fixture-session', 'csrf' => 'fixture-csrf'];
    $deviceScheduledFirst = $deviceExecutor->execute('vippoints', $deviceAccount);
    pointsCheck(($deviceScheduledFirst['retry_after_seconds'] ?? null) === 300, 'Device prerequisite failure was not scheduled for retry');
    $deviceJob = app\service\BilibiliTaskExecutor::jobUpdates($deviceScheduledFirst, [], '42', '09:00', $deviceClock);
    $deviceConfig = unserialize($deviceJob['data'], ['allowed_classes' => false]);
    pointsSubmitted($deviceConfig['_bilibili_verification'], [], 'Device prerequisite retry forged an action submission');
    $scheduledDevice->overrides[$preflightReadPath] = [new RuntimeException('fixture verification GET unavailable')];
    $deviceClock += 300;
    $deviceScheduledNext = $deviceExecutor->execute('vippoints', $deviceAccount, $deviceConfig);
    pointsCheck(($deviceScheduledNext['retry_after_seconds'] ?? null) === 300
        && ($deviceScheduledNext['verification_state']['attempt'] ?? null) === 2,
        'A temporary verification read failure dropped a legitimate pending task with no submissions');
    pointsCheck(!$deviceScheduledNext['account_invalid'] && $scheduledDevice->posts() === [],
        'Read-only prerequisite verification mutated upstream or invalidated the account');
}

$lostRead = new PointsTransport(pointsTaskFixture());
$lostRead->overrides['/x/vip_point/task/combine'] = [$lostRead->tasks, new RuntimeException('fixture read-back unavailable')];
$lostReadResult = (new VipPoints(pointsClient($lostRead)))->run();
pointsCheck($lostReadResult['code'] === 0 && !empty($lostReadResult['pending_verification']), 'Failed read-back fabricated successful settlement');
pointsSubmitted($lostReadResult, ['sign', 'bonus', 'privilege', 'dress-view'], 'Failed read-back discarded the submission lease');

$risk = new PointsTransport(pointsTaskFixture());
$risk->overrides['/pgc/activity/score/task/sign2'] = [['code' => 73319, 'message' => '账号异常，暂不可操作']];
$riskResult = (new VipPoints(pointsClient($risk)))->run();
pointsCheck($riskResult['code'] === 0 && str_contains($riskResult['message'], '账号异常'), 'Upstream risk refusal was hidden or reported as success');
pointsCheck(count($risk->posts()) === 1, 'Risk refusal did not stop further mutations in the current run');
pointsSubmitted($riskResult, ['sign'], 'Risk refusal claimed that unattempted browse tasks were submitted');

// The automatic five-minute hop must also respect a risk refusal, not only the current loop.
$riskScheduled = new PointsTransport(pointsTaskFixture());
$riskScheduled->overrides['/pgc/activity/score/task/sign2'] = [['code' => 73319, 'message' => '账号异常，暂不可操作']];
$riskScheduledClient = pointsClient($riskScheduled);
$riskClock = strtotime('2026-11-11 09:00:00');
$riskExecutor = new app\service\BilibiliTaskExecutor(
    static fn(array $account, array $config): BiliHelper => new BiliHelper(
        $account['mid'], $account['mid_md5'], $account['token'], $account['csrf'], '', $config, $riskScheduledClient
    ),
    static function () use (&$riskClock): int { return $riskClock; }
);
$riskAccount = ['mid' => '42', 'mid_md5' => 'fixture-md5', 'token' => 'fixture-session', 'csrf' => 'fixture-csrf'];
$riskScheduledFirst = $riskExecutor->execute('vippoints', $riskAccount);
pointsCheck($riskScheduledFirst['code'] === 0 && !$riskScheduledFirst['account_invalid'], 'Risk refusal became success or invalidated valid credentials');
pointsCheck(!isset($riskScheduledFirst['retry_after_seconds']) && !isset($riskScheduledFirst['verification_state']),
    'Explicit risk refusal scheduled another automatic points pass');
$riskJobData = app\service\BilibiliTaskExecutor::jobUpdates($riskScheduledFirst, ['_bilibili_verification' => [
    'date' => '2026-11-11', 'attempt' => 2, 'points_submitted' => ['bonus'],
]], '42', '09:00', $riskClock);
$riskConfig = unserialize($riskJobData['data'], ['allowed_classes' => false]);
pointsCheck(!isset($riskConfig['_bilibili_verification']) && $riskJobData['nextExecute'] > $riskClock + 3600,
    'Risk refusal retained an automatic five-minute retry in stored job data');
pointsCheck(str_contains($riskScheduledFirst['message'], '客户端') && str_contains($riskScheduledFirst['message'], '验证'),
    'Risk refusal did not explain the required client verification');
pointsCheck(count($riskScheduled->posts()) === 1, 'Scheduled risk refusal submitted unrelated points tasks');

// Another source may add points while these actions are still unconfirmed.
$unrelatedCredit = new PointsTransport(pointsTaskFixture());
$unrelatedCredit->settleOnSubmit = false;
$creditedSnapshot = $unrelatedCredit->tasks;
$creditedSnapshot['data']['point_info']['point'] = 800;
$unrelatedCredit->overrides['/x/vip_point/task/combine'] = [$unrelatedCredit->tasks, $creditedSnapshot];
$unrelatedCreditResult = (new VipPoints(pointsClient($unrelatedCredit)))->run();
pointsCheck($unrelatedCreditResult['code'] === 0 && !empty($unrelatedCreditResult['pending_verification']),
    'An unrelated balance increase was mistaken for completion of the submitted tasks');

foreach ([0, 2, 3, 99] as $status) {
    $blocked = new PointsTransport(pointsTaskFixture());
    $blocked->tasks['data']['vip_info'] = ['type' => $status === 0 ? 0 : 2, 'status' => $status];
    $blockedResult = (new VipPoints(pointsClient($blocked)))->run();
    pointsCheck($blockedResult['code'] === ($status === 0 ? 1 : 0), 'Non-VIP or restricted membership was misclassified');
    pointsCheck(empty($blockedResult['pending_verification']) && $blocked->posts() === [], 'Ineligible membership reached a points mutation');
}

$malformedFixtures = [
    ['code' => 0, 'data' => []],
    ['code' => 0, 'data' => ['vip_info' => ['type' => 2, 'status' => 1]]],
    pointsTaskFixture(array_replace($completeStates, ['bonus' => 99]), true),
];
$badModules = pointsTaskFixture($completeStates, true);
$badModules['data']['task_info']['modules'] = [['common_task_item' => 'malformed']];
$malformedFixtures[] = $badModules;
$duplicate = pointsTaskFixture($completeStates, true);
$duplicate['data']['task_info']['modules'][] = ['common_task_item' => [['task_code' => 'bonus', 'state' => 1]]];
$malformedFixtures[] = $duplicate;
$unknownSignGroup = pointsTaskFixture($completeStates, false, 99);
$malformedFixtures[] = $unknownSignGroup;
foreach ($malformedFixtures as $fixture) {
    $malformed = new PointsTransport($fixture);
    $malformedResult = (new VipPoints(pointsClient($malformed)))->run();
    pointsCheck($malformedResult['code'] === 0, 'Malformed or unknown task-center state became success');
    pointsCheck($malformed->posts() === [], 'Malformed task-center data caused a mutation');
}
$badSign = new PointsTransport(pointsTaskFixture($completeStates, false));
$badSign->overrides['/x/vip/vip_center/sign_in/three_days_sign'] = [['code' => 0, 'data' => []]];
$badSignResult = (new VipPoints(pointsClient($badSign)))->run();
pointsCheck($badSignResult['code'] === 0 && $badSign->posts() === [], 'Missing current sign state was guessed or posted');

// Authentication preflight is also a read: a connection failure cannot revoke a submitted action.
$navDelayed = new PointsTransport(pointsTaskFixture());
$navDelayed->settleOnSubmit = false;
$navClient = pointsClient($navDelayed);
$navFirstHelper = new BiliHelper('42', 'fixture-md5', 'fixture-session', 'fixture-csrf', '', [], $navClient);
$navFirst = $navFirstHelper->vippoints();
pointsCheck(!empty($navFirst['pending_verification']), 'Public helper did not preserve points settlement');
$navPostCount = count($navDelayed->posts());
$navDelayed->overrides['/x/web-interface/nav'] = [new RuntimeException('fixture nav temporarily unavailable')];
$navRetryConfig = ['verification_only' => true, 'points_submitted' => $navFirst['points_submitted']];
$navRetryHelper = new BiliHelper('42', 'fixture-md5', 'fixture-session', 'fixture-csrf', '', $navRetryConfig, $navClient);
$navRetry = $navRetryHelper->vippoints();
pointsCheck($navRetry['code'] === 0 && !empty($navRetry['pending_verification']) && !$navRetryHelper->cookiezt,
    'A temporary nav preflight failure dropped pending points or invalidated the account');
pointsSubmitted($navRetry, ['sign', 'bonus', 'privilege', 'dress-view'], 'Temporary nav failure forgot prior points mutations');
pointsCheck(count($navDelayed->posts()) === $navPostCount, 'Failed nav verification repeated a points mutation');
$navDelayed->overrides['/x/web-interface/nav'] = [['code' => -101, 'message' => '账号未登录']];
$navExpiredHelper = new BiliHelper('42', 'fixture-md5', 'fixture-session', 'fixture-csrf', '', $navRetryConfig, $navClient);
$navExpired = $navExpiredHelper->vippoints();
pointsCheck($navExpired['code'] === 0 && empty($navExpired['pending_verification']) && $navExpiredHelper->cookiezt,
    'Explicitly expired credentials kept retrying points verification');
pointsCheck(count($navDelayed->posts()) === $navPostCount, 'Expired credentials reached a points mutation');

// The public helper must preserve expired-account status for scheduler account handling.
$expired = new PointsTransport(['code' => -101, 'message' => '账号未登录']);
$helper = new BiliHelper('42', 'fixture-md5', 'fixture-session', 'fixture-csrf', '', [], pointsClient($expired));
$expiredResult = $helper->vippoints();
pointsCheck($expiredResult['code'] === 0 && $helper->cookiezt && $expired->posts() === [], 'Points authentication expiry did not reach the public helper');

echo "Bilibili VIP points tests passed\n";
