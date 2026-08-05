<?php

declare(strict_types=1);

namespace app\index\controller;

use app\index\model\Accounts;
use app\index\model\Jobs;
use douyin\sdk\Client;
use think\facade\Request;
use think\facade\Session;
use Throwable;

final class Douyin
{
    private const SESSION_KEY = 'douyin_qr_login_sessions';
    private const SESSION_ID_BYTES = 24;
    private const PENDING_TTL = 300;
    private const COMPLETED_TTL = 600;

    protected $middleware = [
        \app\middleware\CheckLoginUser::class,
        \app\middleware\CheckAjaxRequest::class,
    ];

    public function handle($act = null)
    {
        return match ((string)$act) {
            'start' => $this->start(),
            'poll' => $this->poll(),
            'state' => $this->currentState(),
            'cancel' => $this->cancel(),
            'delete' => $this->deleteAccount(),
            default => resultJson(0, '不支持的操作'),
        };
    }

    private function start()
    {
        try {
            $client = new Client();
            $payload = $client->qrGenerate();
        } catch (Throwable $exception) {
            return resultJson(0, '抖音登录协议初始化失败');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $qrcode = $this->pngBase64((string)($data['qrcode'] ?? ''));
        $token = trim((string)($data['token'] ?? ''));
        if ((int)($data['error_code'] ?? -1) !== 0 || $qrcode === '' || $token === '') {
            return resultJson(0, $this->upstreamMessage($payload, '获取抖音二维码失败'));
        }

        $state = $client->state();
        $sessionId = bin2hex(random_bytes(self::SESSION_ID_BYTES));
        $expiresAt = $this->pendingExpiry((int)($state['expire_time'] ?? 0));
        $sessions = $this->sessions();
        $sessions[$sessionId] = [
            'state' => $state,
            'created_at' => time(),
            'expires_at' => $expiresAt,
        ];
        $this->saveSessions($sessions);

        return resultJson(1, '二维码已生成', [
            'login_id' => $sessionId,
            'qrimg' => $qrcode,
            'status' => 'new',
            'expire_time' => $expiresAt,
            'verification' => $client->publicState()['verification'],
        ]);
    }

    private function poll()
    {
        $sessionId = $this->loginId();
        if ($sessionId === '') {
            return resultJson(0, '登录会话参数错误');
        }
        $sessions = $this->sessions(false);
        $entry = $sessions[$sessionId] ?? null;
        if (!is_array($entry) || !is_array($entry['state'] ?? null)) {
            return resultJson(0, '登录会话不存在或已失效');
        }
        if ((int)($entry['expires_at'] ?? 0) < time()
            && (string)($entry['state']['status'] ?? '') !== 'confirmed'
        ) {
            unset($sessions[$sessionId]);
            $this->saveSessions($sessions);
            return resultJson(1, '二维码已过期', [
                'status' => 'expired',
                'authenticated' => false,
                'verification' => [
                    'required' => false,
                    'mode' => 'none',
                    'url' => '',
                    'verify_data' => '',
                    'description' => '',
                ],
            ]);
        }

        try {
            $client = new Client(config: ['state' => $entry['state']]);
            $payload = $client->qrPoll();
        } catch (Throwable $exception) {
            return resultJson(0, '查询抖音扫码状态失败');
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $publicState = $client->publicState();
        $state = $client->state();
        $status = (string)$publicState['status'];
        $authenticated = (bool)$publicState['authenticated'];

        $entry['state'] = $state;
        $entry['expires_at'] = $authenticated
            ? time() + self::COMPLETED_TTL
            : $this->pendingExpiry((int)($state['expire_time'] ?? 0));
        if ($authenticated) {
            $entry['completed_at'] = time();
        }
        $sessions[$sessionId] = $entry;
        $this->saveSessions($sessions);

        if ($authenticated) {
            $stored = $this->storeAccount($client);
            $storedPayload = $this->responsePayload($stored);
            if ((int)($storedPayload['code'] ?? 0) === 1) {
                unset($sessions[$sessionId]);
                $this->saveSessions($sessions);
                return resultJson(1, (string)($storedPayload['message'] ?? '登录成功'), [
                    'status' => 'confirmed',
                    'authenticated' => true,
                    'account' => $client->profile(),
                ]);
            }
            return $stored;
        }

        $response = [
            'status' => $status,
            'authenticated' => $authenticated,
            'expire_time' => (int)($state['expire_time'] ?? 0),
            'verification' => $publicState['verification'],
        ];
        $renewedQr = $this->pngBase64((string)($data['qrcode'] ?? ''));
        if ($renewedQr !== '') {
            $response['qrimg'] = $renewedQr;
        }

        if ($status === 'confirmed' && !$authenticated) {
            return resultJson(0, '已确认登录，但未收到有效会话凭据', $response);
        }
        $errorCode = (int)($data['error_code'] ?? -1);
        if ($errorCode !== 0 && empty($publicState['verification']['required'])) {
            return resultJson(0, $this->upstreamMessage($payload, '查询抖音扫码状态失败'), $response);
        }
        return resultJson(1, $this->statusMessage($status, $publicState), $response);
    }

    private function currentState()
    {
        $sessionId = $this->loginId();
        $sessions = $this->sessions();
        $entry = $sessionId !== '' ? ($sessions[$sessionId] ?? null) : null;
        if (!is_array($entry) || !is_array($entry['state'] ?? null)) {
            return resultJson(0, '登录会话不存在或已失效');
        }
        try {
            $client = new Client(config: ['state' => $entry['state']]);
        } catch (Throwable $exception) {
            return resultJson(0, '登录会话读取失败');
        }
        return resultJson(1, '获取成功', $client->publicState());
    }

    private function cancel()
    {
        $sessionId = $this->loginId();
        $sessions = $this->sessions(false);
        if ($sessionId !== '') {
            unset($sessions[$sessionId]);
            $this->saveSessions($sessions);
        }
        return resultJson(1, '登录会话已取消');
    }

    private function deleteAccount()
    {
        $userId = trim((string)Request::post('user_id', ''));
        $uid = (int)Session::get('user.uid');
        if (!$this->validUserId($userId) || $uid <= 0) {
            return resultJson(0, '账号参数错误');
        }

        $account = Accounts::where('type', 'douyin')
            ->where('user_id', $userId)
            ->where('uid', $uid)
            ->find();
        if (!$account) {
            return resultJson(0, '账号不存在或无权操作');
        }

        Accounts::where('type', 'douyin')
            ->where('user_id', $userId)
            ->where('uid', $uid)
            ->delete();
        Jobs::where('type', 'douyin')
            ->where('user_id', $userId)
            ->where('uid', $uid)
            ->delete();
        return resultJson(1, '删除成功');
    }

    private function storeAccount(Client $client)
    {
        $profile = $client->profile();
        $userId = trim((string)($profile['user_id'] ?? ''));
        if (!$this->validUserId($userId)) {
            return resultJson(0, '已完成扫码，但未获取到抖音账号标识');
        }

        $credentials = $client->credentials();
        $cookies = is_array($credentials['cookies'] ?? null) ? $credentials['cookies'] : [];
        $cookieHeader = trim((string)($credentials['cookie_header'] ?? ''));
        if ($cookieHeader === '') {
            return resultJson(0, '已完成扫码，但登录凭据不完整');
        }

        $uid = (int)Session::get('user.uid');
        if (Accounts::where('type', 'douyin')
            ->where('user_id', $userId)
            ->where('uid', '<>', $uid)
            ->find()
        ) {
            return resultJson(-1, '系统已存在该账号，无法继续添加');
        }

        $nickname = trim((string)($profile['nickname'] ?? ''));
        $accountData = [
            'user_id' => $userId,
            'sec_user_id' => trim((string)($profile['sec_user_id'] ?? '')),
            'nickname' => $nickname !== '' ? $nickname : '抖音用户 ' . substr($userId, 0, 8),
            'avatar' => (string)($profile['avatar'] ?? ''),
            'cookies' => $cookies,
            'cookie' => $cookieHeader,
            'updated_at' => time(),
        ];

        try {
            return Accounts::add('douyin', $userId, $accountData);
        } catch (Throwable $exception) {
            return resultJson(0, '抖音账号保存失败，请稍后重试');
        }
    }

    private function validUserId(string $userId): bool
    {
        $length = strlen($userId);
        return $length >= 4
            && $length <= 255
            && (bool)preg_match('/^[A-Za-z0-9_-]+$/D', $userId);
    }

    /** @return array<string,mixed> */
    private function responsePayload(mixed $response): array
    {
        if (is_object($response) && method_exists($response, 'getData')) {
            $response = $response->getData();
        }
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            $response = is_array($decoded) ? $decoded : [];
        }
        return is_array($response) ? $response : [];
    }

    private function loginId(): string
    {
        $sessionId = trim((string)Request::post('login_id', ''));
        return preg_match('/^[a-f0-9]{48}$/', $sessionId) ? $sessionId : '';
    }

    /** @return array<string,array<string,mixed>> */
    private function sessions(bool $prune = true): array
    {
        $sessions = Session::get(self::SESSION_KEY, []);
        $sessions = is_array($sessions) ? $sessions : [];
        if (!$prune) {
            return $sessions;
        }
        $now = time();
        foreach ($sessions as $id => $entry) {
            if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) < $now) {
                unset($sessions[$id]);
            }
        }
        $this->saveSessions($sessions);
        return $sessions;
    }

    /** @param array<string,array<string,mixed>> $sessions */
    private function saveSessions(array $sessions): void
    {
        Session::set(self::SESSION_KEY, $sessions);
    }

    private function pendingExpiry(int $upstreamExpiry): int
    {
        $fallback = time() + self::PENDING_TTL;
        return $upstreamExpiry > time() ? min($upstreamExpiry, $fallback) : $fallback;
    }

    private function pngBase64(string $value): string
    {
        if (str_starts_with($value, 'data:image/png;base64,')) {
            $value = substr($value, strlen('data:image/png;base64,'));
        }
        if ($value === '' || strlen($value) > 2097152) {
            return '';
        }
        $image = base64_decode($value, true);
        return is_string($image) && str_starts_with($image, "\x89PNG\r\n\x1a\n") ? $value : '';
    }

    /** @param array<string,mixed> $payload */
    private function upstreamMessage(array $payload, string $fallback): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $message = trim((string)($data['description'] ?? $payload['message'] ?? ''));
        return $message !== '' && strtolower($message) !== 'success' ? $message : $fallback;
    }

    /** @param array<string,mixed> $publicState */
    private function statusMessage(string $status, array $publicState): string
    {
        if (!empty($publicState['verification']['required'])) {
            return '请完成人机验证';
        }
        return match ($status) {
            'new' => '等待扫码',
            'scanned' => '已扫码，请在抖音 APP 中确认',
            'confirmed' => '登录成功',
            'refused' => '本次登录已拒绝',
            'expired' => '二维码已过期',
            default => trim((string)($publicState['message'] ?? '')) ?: '等待扫码状态更新',
        };
    }
}
