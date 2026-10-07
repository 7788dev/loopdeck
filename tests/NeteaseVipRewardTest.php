<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

final class VipRewardFixture extends netease\Netease
{
    public string $scenario = 'settled';
    public int $lists = 0;
    public int $claims = 0;
    public int $balances = 0;
    public function __construct() {}
    public function vip_growthpoint()
    {
        $this->balances++;
        return $this->scenario === 'balance-error' && $this->balances > 1 ? ['code' => 503]
            : ['code' => 200, 'data' => ['userLevel' => ['normal' => true, 'growthPoint' => 100]]];
    }
    public function vip_sign() { return ['code' => 200, 'signed' => true, 'message' => 'fixture signed']; }
    public function vip_timemachine($startTime = null, $endTime = null, $limit = 60) { return ['code' => 200]; }
    public function listen_vip_songs() { return ['code' => 200, 'data' => ['reported' => 3], 'message' => 'fixture listened']; }
    public function get_vip_tasks()
    {
        return $this->scenario === 'legacy-error' ? ['code' => 503, 'message' => 'fixture upstream unavailable']
            : ['code' => 200, 'data' => ['taskList' => []]];
    }
    public function vip_tasks_v1($userId = null)
    {
        $this->lists++;
        if ($this->scenario === 'list-error' || ($this->scenario === 'verify-error' && $this->lists > 1)) {
            return ['code' => 503, 'message' => 'fixture list unavailable'];
        }
        if ($this->scenario === 'malformed-list') {
            return ['code' => 200];
        }
        $worth = $this->scenario === 'empty' || ($this->claims > 0 && $this->scenario !== 'pending') ? 0 : 3;
        return ['code' => 200, 'data' => $worth === 0 ? [] : [['historyUnObtainRewardWorth' => $worth]]];
    }
    public function vip_growthpoint_getall()
    {
        $this->claims++;
        return $this->scenario === 'claim-error' ? ['code' => 503, 'message' => 'fixture claim unavailable'] : ['code' => 200];
    }
}
foreach (['settled', 'empty', 'legacy-error', 'list-error', 'malformed-list', 'claim-error', 'verify-error', 'pending', 'balance-error'] as $scenario) {
    $fixture = new VipRewardFixture();
    $fixture->scenario = $scenario;
    $result = $fixture->vip_growth_task();
    $success = in_array($scenario, ['settled', 'empty'], true);
    if (($result['code'] === 200) !== $success) {
        throw new RuntimeException('Incorrect VIP reward outcome for ' . $scenario);
    }
    if (in_array($scenario, ['list-error', 'malformed-list', 'empty'], true) && $fixture->claims !== 0) {
        throw new RuntimeException('Reward claim submitted without a known pending reward');
    }
    if ($scenario === 'pending' && !str_contains($result['message'], '待入账')) {
        throw new RuntimeException('Unsettled reward was not explained');
    }
    if ($scenario === 'list-error' && ($result['data']['upstream_errors'][0]['code'] ?? 0) !== 503) {
        throw new RuntimeException('Upstream failure reason was discarded');
    }
    if (!$success && str_contains($result['message'], '将自动重试')) {
        throw new RuntimeException('Reward failure promised a retry that is not scheduled');
    }
}
echo "NetEase VIP reward outcome tests passed: failed/malformed reads, failed claims, pending settlement and verified empty results\n";

