<?php

if (!function_exists('safe_unserialize_array')) {
    /**
     * Decode legacy array payloads without allowing PHP object construction.
     */
    function safe_unserialize_array(mixed $payload): array
    {
        if (!is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = @unserialize($payload, ['allowed_classes' => false]);
        } catch (Throwable $exception) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $pending = [$decoded];
        while ($pending !== []) {
            $current = array_pop($pending);
            foreach ($current as $value) {
                if (is_object($value) || is_resource($value)) {
                    return [];
                }
                if (is_array($value)) {
                    $pending[] = $value;
                }
            }
        }

        return $decoded;
    }
}

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('resultJson')) {
    function resultJson(int $code, string $message = '', $data = null)
    {
        $payload = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }

        return function_exists('json')
            ? json($payload, 200, ['Content-Type' => 'application/json; charset=utf-8'])
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('app_version')) {
    function app_version(): string
    {
        return \app\service\ApplicationVersion::current();
    }
}

if (!function_exists('real_ip')) {
    function real_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }
}

if (!function_exists('get_Domain')) {
    /**
     * Absolute base URL for links this application hands to third parties
     * (password-reset mails, payment gateway callbacks, scheduler self-calls).
     *
     * `Host` is attacker controlled, so it is only echoed back when it matches
     * a domain this site is actually registered under. Anything else falls back
     * to the registered domain, which turns Host header poisoning into a no-op.
     */
    function get_Domain(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[a-z0-9.:\-\[\]]+$/i', $host)) {
            $host = '';
        }

        $registered = array_values(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            [config('web.domain'), config('web.domain2')]
        ), static fn(string $value): bool => $value !== ''));

        $isLoopback = $host !== ''
            && preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host) === 1;
        $trusted = $host !== ''
            && ($registered === [] || $isLoopback || in_array($host, $registered, true));

        if (!$trusted) {
            $host = (string)($registered[0] ?? '');
        }
        if ($host === '') {
            $host = '127.0.0.1';
        }

        return ($https ? 'https://' : 'http://') . $host . '/';
    }
}

if (!function_exists('safe_html')) {
    /**
     * Render operator-authored rich text.
     *
     * Notices are written by administrators and may contain simple formatting,
     * but a sub-station owner is a paying, self-service role, so the content is
     * still untrusted. Keep the formatting tags and remove everything that can
     * execute: unknown tags, event handlers, inline styles and script URLs.
     */
    function safe_html(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = strip_tags($value, '<p><br><b><strong><i><em><u><s><ul><ol><li><h4><h5><h6><span><div><a><code><pre><blockquote>');
        $value = preg_replace('/\son[a-z-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value) ?? '';
        $value = preg_replace('/\sstyle\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value) ?? '';
        $value = preg_replace(
            '/\s(?:href|src|xlink:href|formaction|action)\s*=\s*(["\']?)\s*(?:javascript|vbscript|data)\s*:[^"\'>\s]*\1/i',
            '',
            $value
        ) ?? '';

        return $value;
    }
}

if (!function_exists('js_string')) {
    /**
     * Quote a value for direct interpolation into a JavaScript string literal.
     */
    function js_string(?string $value): string
    {
        $encoded = json_encode(
            (string)$value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
        return $encoded === false ? '""' : $encoded;
    }
}

if (!function_exists('safe_http_url')) {
    /**
     * Accept only absolute http(s) URLs, so a stored value can never turn a
     * redirect or a link into `javascript:` execution.
     */
    function safe_http_url(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 2048) {
            return '';
        }
        if (!preg_match('#\Ahttps?://#i', $value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            return '';
        }
        return $value;
    }
}

if (!function_exists('is_cross_origin_request')) {
    /**
     * True only when the browser positively reported a foreign origin.
     *
     * Endpoints reached by a plain HTML form carry no AJAX header and no CSRF
     * token. A missing `Origin`/`Referer` is not treated as an attack, because
     * the SameSite=Lax session cookie already blocks cross-site form posts;
     * this check exists to reject the cases the browser does tell us about.
     */
    function is_cross_origin_request(): bool
    {
        $source = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($source === '') {
            $source = (string)($_SERVER['HTTP_REFERER'] ?? '');
        }
        if ($source === '') {
            return false;
        }

        $parts = parse_url($source);
        if (!is_array($parts) || empty($parts['host'])) {
            return true;
        }
        $scheme = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $current = parse_url($scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!is_array($current) || empty($current['host'])) {
            return true;
        }
        $sourceScheme = strtolower((string)($parts['scheme'] ?? ''));
        return $sourceScheme !== $scheme
            || strtolower((string)$parts['host']) !== strtolower((string)$current['host'])
            || (int)($parts['port'] ?? ($sourceScheme === 'https' ? 443 : 80))
                !== (int)($current['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
}

if (!function_exists('html_escape')) {
    function html_escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('getRandStr')) {
    function getRandStr(int $length = 16, int $type = 0): string
    {
        $alphabets = [
            '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789',
            'abcdefghijklmnopqrstuvwxyz',
        ];
        $alphabet = $alphabets[$type] ?? $alphabets[0];
        $last = strlen($alphabet) - 1;
        $value = '';
        for ($index = 0; $index < max(1, $length); $index++) {
            $value .= $alphabet[random_int(0, $last)];
        }
        return $value;
    }
}

if (!function_exists('get_Prefix')) {
    function get_Prefix(int $length = 6): string
    {
        return 'site' . strtolower(getRandStr(max(2, $length), 2));
    }
}

if (!function_exists('check_mail')) {
    function check_mail(string $mail): bool
    {
        return filter_var($mail, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('get_qqname')) {
    function get_qqname($qq): string
    {
        $qq = preg_replace('/\D+/', '', (string)$qq);
        return $qq === '' ? 'LoopDeck 用户' : 'QQ' . $qq;
    }
}

if (!function_exists('get_ip_city')) {
    function get_ip_city(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) ? '' : '';
    }
}

if (!function_exists('get_os')) {
    function get_os(): string
    {
        $agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        return match (true) {
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'iphone'), str_contains($agent, 'ipad') => 'iOS',
            str_contains($agent, 'mac os') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => 'Unknown',
        };
    }
}

if (!function_exists('get_curl')) {
    function get_curl(string $url, $data = null, int $timeout = 20)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'LoopDeck/2.0 (+local repaired build)',
        ]);
        if ($data !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt(
                $handle,
                CURLOPT_POSTFIELDS,
                is_array($data) ? http_build_query($data) : (string)$data
            );
        }

        $response = curl_exec($handle);
        curl_close($handle);
        return $response;
    }
}

if (!function_exists('curl_get')) {
    function curl_get(string $url)
    {
        return get_curl($url);
    }
}

if (!function_exists('send_mail')) {
    function send_mail(string $to, string $subject, string $body): bool
    {
        if (!check_mail($to) || !class_exists(PHPMailer::class)) {
            return false;
        }

        $host = (string)config('sys.mail_smtp', '');
        $username = (string)config('sys.mail_name', '');
        $password = (string)config('sys.mail_pwd', '');
        $port = (int)config('sys.mail_port', 465);
        if ($host === '' || $username === '' || $password === '') {
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->Port = $port;
            $mail->SMTPSecure = $port === 465
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 8;
            $mail->getSMTPInstance()->Timelimit = 8;
            $mail->setFrom($username, (string)config('web.webname', 'LoopDeck'));
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            return $mail->send();
        } catch (\Throwable $exception) {
            return false;
        }
    }
}

if (!function_exists('get_mail_tempale')) {
    function get_mail_tempale(int $type, $user, $parameter = null): string
    {
        if (is_object($user) && method_exists($user, 'toArray')) {
            $user = $user->toArray();
        }
        $site = htmlspecialchars((string)config('web.webname', 'LoopDeck'), ENT_QUOTES, 'UTF-8');
        $nickname = htmlspecialchars(
            is_array($user) ? (string)($user['nickname'] ?? '用户') : (string)$user,
            ENT_QUOTES,
            'UTF-8'
        );
        $value = htmlspecialchars((string)$parameter, ENT_QUOTES, 'UTF-8');
        $text = match ($type) {
            1 => "您的验证码是：<strong>{$value}</strong>，5 分钟内有效。",
            2 => "请点击下面的链接重置密码：<br><a href=\"{$value}\">{$value}</a>",
            3 => "您的 {$value} 账号状态已失效，请登录后更新凭据。",
            4 => '您的会员已到期，请登录后续费。',
            default => $value,
        };
        return "<div style=\"font-family:Arial,sans-serif;line-height:1.8\"><h2>{$site}</h2>"
            . "<p>您好，{$nickname}：</p><p>{$text}</p></div>";
    }
}

if (!function_exists('is_Vip_Day')) {
    function is_Vip_Day($id): int
    {
        return [1 => 30, 2 => 90, 3 => 180, 4 => 365, 5 => 3, 6 => 7][(int)$id] ?? 0;
    }
}

if (!function_exists('is_Vip_Month')) {
    function is_Vip_Month($id): int
    {
        return [1 => 1, 2 => 3, 3 => 6, 4 => 12, 5 => 0, 6 => 0][(int)$id] ?? 0;
    }
}

if (!function_exists('is_Quota_Num')) {
    function is_Quota_Num($id): int
    {
        return [1 => 1, 2 => 3, 3 => 5, 4 => 10][(int)$id] ?? 0;
    }
}

if (!function_exists('is_Site_Day')) {
    function is_Site_Day($id): int
    {
        return [1 => 30, 2 => 90, 3 => 180, 4 => 365][(int)$id] ?? 0;
    }
}

if (!function_exists('is_Agent_Name')) {
    function is_Agent_Name($id): string
    {
        return [0 => '普通用户', 1 => '银牌代理', 2 => '金牌代理', 3 => '钻石代理'][(int)$id] ?? '普通用户';
    }
}

if (!function_exists('microtime_float')) {
    function microtime_float(): float
    {
        return microtime(true);
    }
}

if (!function_exists('filterEmoji')) {
    function filterEmoji(string $value): string
    {
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? $value;
    }
}

if (!function_exists('from_url')) {
    function from_url(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
    }
}
