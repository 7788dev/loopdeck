<?php

declare(strict_types=1);

namespace tieba;

use app\service\CheckinAdapterBase;
use app\service\PlatformException;

/**
 * 百度贴吧 check-in for every followed forum through the client API.
 *
 * Protocol checked 2026-09-23 against lumina37/aiotieba (2026-05..08),
 * BANKA2017/tbsign_go (2026-09-01) and its error-code list (2026-02-10),
 * LuoSue/TiebaSignIn-1 (2026-09-06) and MoeNetwork/Tieba-Cloud-Sign
 * (2026-04-30). tieba.baidu.com/dc/common/tbs now redirects to plain HTTP,
 * so the tbs is taken from /c/s/login over HTTPS instead.
 */
final class Tieba extends CheckinAdapterBase
{
    protected const HOSTS = ['c.tieba.baidu.com', 'tieba.baidu.com'];
    private const CLIENT = ['_client_type' => '2', '_client_version' => '9.7.8.0', '_phone_imei' => '000000000000000',
        'model' => 'MI+5', 'net_type' => '1'];
    /** Signed already, or nothing the account can do about it today. */
    private const DONE = ['0', '160002'];
    private const SKIP = ['340006', '2280001', '300004', '340008'];
    /** Rate limits and busy answers: wait, without using up an attempt. */
    private const BACKOFF = ['340011', '340016', '2280007', '1989004', '255', '160003', '160008', '220034'];
    private const ACCOUNT = ['110000' => '贴吧登录状态已失效，请更新 BDUSS',
        '1990055' => '贴吧要求账号完成实名认证，请在贴吧 App 中处理后重新执行',
        '2280015' => '贴吧账号已被封禁', '3250002' => '贴吧账号状态异常，请在贴吧 App 中检查',
        '3250004' => '贴吧账号状态异常，请在贴吧 App 中检查'];
    private const STEP_SECONDS = 25.0;
    private const STEP_LIMIT = 30;
    private const MAX_ATTEMPTS = 3;
    private const MAX_BACKOFFS = 6;
    private const MAX_PAGES = 20;

    public static function sign(array $data): array
    {
        unset($data['sign']);
        ksort($data, SORT_STRING);
        $plain = '';
        foreach ($data as $key => $value) {
            $plain .= $key . '=' . $value;
        }
        $data['sign'] = strtoupper(md5($plain . 'tiebaclient!!!'));
        return $data;
    }

    public function authenticate(array $credentials): array
    {
        $this->credentials = ['bduss' => self::bdussFrom($this->secret($credentials, 'cookie'))];
        $user = $this->login()['user'];
        $name = $this->displayName($user);
        return $this->identity((string)($user['id'] ?? ''), $name, self::overview($user, $name),
            self::avatar((string)($user['portrait'] ?? '')));
    }

    public function profile(array $account): array
    {
        $this->restore($account);
        $user = $this->login()['user'];
        return self::overview($user, trim((string)($account['nickname'] ?? '')) ?: (string)($user['name'] ?? ''));
    }

    public function execute(array $account, array $state = [], ?callable $checkpoint = null): array
    {
        $this->restore($account);
        $today = date('Y-m-d');
        if (($state['date'] ?? '') !== $today) {
            $state = ['date' => $today, 'page' => 1, 'scanned' => false, 'forums' => [], 'done' => [],
                'skipped' => [], 'failed' => [], 'attempts' => [], 'backoffs' => 0, 'bonus' => 0];
        }
        $save = static function () use (&$state, $checkpoint): void {
            if ($checkpoint !== null) {
                $checkpoint($state);
            }
        };
        $tbs = $this->login()['tbs'];
        while (!$state['scanned']) {
            if ($this->remaining() < 10) {
                return $this->progressResult('列表=继续读取 | 已发现=' . count($state['forums']), $state, 60);
            }
            $page = (int)$state['page'];
            $response = $this->post('/c/f/forum/like', self::CLIENT + [
                'BDUSS' => $this->bduss(), '_client_id' => 'wappc_1534235498291_488', 'from' => '1008621y',
                'page_no' => (string)$page, 'page_size' => '200', 'timestamp' => (string)time(), 'vcode_tag' => '11',
            ]);
            $code = (string)($response['error_code'] ?? '0');
            if ($code !== '0') {
                $this->accountLevel($code);
                throw new PlatformException('关注贴吧列表读取失败，稍后重试');
            }
            // An account without followed forums has no forum_list at all.
            foreach (self::forums($response['forum_list'] ?? []) as $id => $name) {
                $state['forums'][$id] = $name;
            }
            $state['page'] = $page + 1;
            $state['scanned'] = !self::yes($response['has_more'] ?? false) || $page >= self::MAX_PAGES;
            $save();
        }

        $stepEnds = microtime(true) + self::STEP_SECONDS;
        $processed = 0;
        foreach ($state['forums'] as $id => $name) {
            $id = (string)$id;
            if (isset($state['done'][$id]) || isset($state['skipped'][$id]) || isset($state['failed'][$id])) {
                continue;
            }
            if ($processed >= self::STEP_LIMIT || microtime(true) >= $stepEnds || $this->remaining() < 8) {
                break;
            }
            if ($processed > 0) {
                $this->pause(1.0);
            }
            $processed++;
            try {
                $response = $this->post('/c/c/forum/sign', self::CLIENT + ['BDUSS' => $this->bduss(), 'fid' => $id,
                    'kw' => (string)$name, 'tbs' => $tbs, 'timestamp' => (string)time()]);
            } catch (PlatformException $exception) {
                if ($exception->accountInvalid || $exception->retryAfter === 0) {
                    throw $exception;
                }
                $save();
                return $this->progressResult('签到=网络波动，稍后继续 | ' . self::counts($state), $state,
                    max(60, $exception->retryAfter));
            }
            $code = (string)($response['error_code'] ?? 'missing');
            if (in_array($code, self::DONE, true)) {
                $state['done'][$id] = true;
                $state['bonus'] += max(0, (int)($response['user_info']['sign_bonus_point'] ?? 0));
            } elseif (in_array($code, self::SKIP, true)) {
                $state['skipped'][$id] = $code;
            } elseif (in_array($code, self::BACKOFF, true)) {
                $state['backoffs'] = (int)$state['backoffs'] + 1;
                if ($state['backoffs'] <= self::MAX_BACKOFFS) {
                    $save();
                    return $this->progressResult('签到=平台限流，稍后继续 | ' . self::counts($state), $state, 300);
                }
                $this->fail($state, $id, $code);
            } elseif ($code === '320022') {
                // tbs rejected: take a fresh one; the forum is retried later.
                $tbs = $this->login()['tbs'];
                $state['attempts'][$id] = (int)($state['attempts'][$id] ?? 0) + 1;
                if ($state['attempts'][$id] >= self::MAX_ATTEMPTS) {
                    $this->fail($state, $id, $code);
                }
            } elseif ($code === '1' || isset(self::ACCOUNT[$code])) {
                // "Not logged in" can be a false alarm; confirm it first.
                $this->accountLevel($code === '1' ? '' : $code);
                $this->login();
                $state['attempts'][$id] = (int)($state['attempts'][$id] ?? 0) + 1;
                if ($state['attempts'][$id] >= self::MAX_ATTEMPTS) {
                    $this->fail($state, $id, $code);
                }
            } else {
                $state['attempts'][$id] = (int)($state['attempts'][$id] ?? 0) + 1;
                if ($state['attempts'][$id] >= self::MAX_ATTEMPTS) {
                    $this->fail($state, $id, $code);
                }
            }
            $save();
        }

        $pending = self::pending($state);
        if ($pending > 0) {
            return $this->progressResult('签到进度=' . self::counts($state), $state, 60);
        }
        $total = count($state['forums']);
        if ($total === 0) {
            return $this->result(true, '关注贴吧=0 | 无需签到', $state, 0, self::summary($state));
        }
        $message = '贴吧数=' . $total . ' | 成功=' . count($state['done']) . ' | 跳过=' . count($state['skipped'])
            . ' | 失败=' . count($state['failed']);
        if ((int)$state['bonus'] > 0) {
            $message .= ' | 经验=+' . (int)$state['bonus'];
        }
        return $this->result($state['failed'] === [], $message, $state, 0, self::summary($state));
    }

    private function progressResult(string $message, array $state, int $retryAfter): array
    {
        return ['in_progress' => true] + $this->result(false, $message, $state, $retryAfter, self::summary($state));
    }

    private function fail(array &$state, string $id, string $code): void
    {
        $state['failed'][$id] = preg_match('/\A[0-9a-z]{1,12}\z/', $code) === 1 ? $code : 'unknown';
    }

    private function accountLevel(string $code): void
    {
        if (isset(self::ACCOUNT[$code])) {
            throw new PlatformException(self::ACCOUNT[$code], true, 0);
        }
    }

    /** @return array{user:array,tbs:string} */
    private function login(): array
    {
        $response = $this->post('/c/s/login', ['bdusstoken' => $this->bduss(), '_client_version' => self::CLIENT['_client_version']]);
        $code = (string)($response['error_code'] ?? '');
        if (in_array($code, ['1990006', '1', '110000'], true)) {
            throw new PlatformException('贴吧登录状态已失效，请更新 BDUSS', true, 0);
        }
        $this->accountLevel($code);
        $tbs = $response['anti']['tbs'] ?? null;
        if ($code !== '0' || !is_array($response['user'] ?? null) || !is_string($tbs) || $tbs === '') {
            throw new PlatformException('贴吧账号信息读取失败，请稍后重试');
        }
        return ['user' => $response['user'], 'tbs' => $tbs];
    }

    private function displayName(array $user): string
    {
        $name = trim((string)($user['name'] ?? ''));
        $portrait = self::portrait((string)($user['portrait'] ?? ''));
        if ($portrait !== '') {
            try {
                $panel = $this->json('GET', 'https://tieba.baidu.com/home/get/panel', ['query' => ['id' => $portrait]]);
                $data = is_array($panel['data'] ?? null) ? $panel['data'] : [];
                $name = trim((string)($data['show_nickname'] ?? '')) ?: (trim((string)($data['name_show'] ?? '')) ?: $name);
            } catch (PlatformException $exception) {
                // The nickname is cosmetic; the login already succeeded.
            }
        }
        return $name !== '' ? $name : '贴吧用户';
    }

    private function post(string $path, array $data): array
    {
        return $this->json('POST', 'https://c.tieba.baidu.com' . $path, [
            'headers' => ['Cookie' => 'BDUSS=' . $this->bduss()],
            'form_params' => self::sign($data),
        ]);
    }

    private function bduss(): string
    {
        return $this->secret($this->credentials, 'bduss', 512);
    }

    /** Accept a bare BDUSS or a pasted Cookie header (BDUSS or BDUSS_BFESS). */
    private static function bdussFrom(string $input): string
    {
        $value = $input;
        if (str_contains($input, '=')) {
            if (preg_match('/(?:^|;\s*)BDUSS=([^;\s]+)/', $input, $match) === 1
                || preg_match('/(?:^|;\s*)BDUSS_BFESS=([^;\s]+)/', $input, $match) === 1) {
                $value = $match[1];
            } else {
                $value = '';
            }
        }
        if (preg_match('/\A[A-Za-z0-9~\-_.]{64,512}\z/', $value) !== 1) {
            throw new PlatformException('未找到有效的 BDUSS，请复制 Cookie 中 BDUSS 的值', true, 0);
        }
        return $value;
    }

    /** @return array<string,string> forum id => exact forum name */
    private static function forums(mixed $list): array
    {
        $forums = [];
        foreach (is_array($list) ? $list : [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach (isset($group['id']) ? [$group] : $group as $forum) {
                $id = is_array($forum) ? (string)($forum['id'] ?? '') : '';
                $name = is_array($forum) && is_string($forum['name'] ?? null) ? $forum['name'] : '';
                if (ctype_digit($id) && $name !== '' && mb_strlen($name) <= 100) {
                    $forums[$id] = $name;
                }
            }
        }
        return $forums;
    }

    private static function portrait(string $portrait): string
    {
        $portrait = explode('?', $portrait, 2)[0];
        return preg_match('/\A[A-Za-z0-9._\-]{1,128}\z/', $portrait) === 1 ? $portrait : '';
    }

    private static function avatar(string $portrait): string
    {
        $portrait = self::portrait($portrait);
        return $portrait === '' ? '' : 'https://himg.bdimg.com/sys/portrait/item/' . $portrait;
    }

    private static function overview(array $user, string $name): array
    {
        return ['rows' => [
            ['label' => '贴吧昵称', 'value' => $name !== '' ? $name : '贴吧用户'],
            ['label' => '贴吧账号 ID', 'value' => (string)($user['id'] ?? '')],
            ['label' => '登录状态', 'value' => '正常'],
        ], 'stats' => []];
    }

    private static function pending(array $state): int
    {
        return max(0, count($state['forums']) - count($state['done']) - count($state['skipped']) - count($state['failed']));
    }

    private static function counts(array $state): string
    {
        return '已签=' . count($state['done']) . '/' . count($state['forums']);
    }

    private static function summary(array $state): array
    {
        $names = static function (array $ids) use ($state): array {
            $result = [];
            foreach (array_slice(array_keys($ids), 0, 12) as $id) {
                $result[] = (string)($state['forums'][$id] ?? $id);
            }
            return $result;
        };
        return ['total' => count($state['forums']), 'done' => count($state['done']),
            'skipped' => count($state['skipped']), 'failed' => count($state['failed']), 'pending' => self::pending($state),
            'bonus' => (int)($state['bonus'] ?? 0), 'failed_names' => $names($state['failed']),
            'skipped_names' => $names($state['skipped'])];
    }
}
