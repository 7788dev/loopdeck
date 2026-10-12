<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/common.php';

/**
 * Reproduces R1: real_ip() must keep using the direct socket peer unless a
 * front proxy is explicitly trusted, and must then recover the real client
 * from X-Forwarded-For without letting a direct client spoof it.
 */
function realIpCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function withTrustedProxies(?string $value, callable $body): void
{
    $previous = getenv('TRUSTED_PROXIES');
    putenv($value === null ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES=' . $value);
    try {
        $body();
    } finally {
        putenv($previous === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES=' . $previous);
    }
}

function withRequest(?string $remote, ?string $forwarded, callable $body): void
{
    $previousRemote = $_SERVER['REMOTE_ADDR'] ?? null;
    $previousForwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    if ($remote === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $remote;
    }
    if ($forwarded === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
    }
    try {
        $body();
    } finally {
        if ($previousRemote === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $previousRemote;
        }
        if ($previousForwarded === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $previousForwarded;
        }
    }
}

// 1. No trusted proxy: the forwarded header is ignored entirely (old default).
withTrustedProxies(null, static function (): void {
    withRequest('203.0.113.9', '198.51.100.1', static function (): void {
        realIpCheck(real_ip() === '203.0.113.9', 'Without trusted proxies the forwarded header must be ignored');
    });
});

// 2. Trusted proxy hop exposes the real client.
withTrustedProxies('10.0.0.0/8', static function (): void {
    withRequest('10.0.0.5', '198.51.100.23', static function (): void {
        realIpCheck(real_ip() === '198.51.100.23', 'A trusted proxy hop must expose the client IP');
    });
});

// 3. Multi-hop is walked right-to-left past every trusted hop.
withTrustedProxies('10.0.0.0/8, 203.0.113.0/24', static function (): void {
    withRequest('10.0.0.5', '198.51.100.23, 203.0.113.7', static function (): void {
        realIpCheck(real_ip() === '198.51.100.23', 'The chain must be walked right-to-left past every trusted hop');
    });
});

// 4. Anti-spoof: a direct client must never control the reported IP via the header.
withTrustedProxies('10.0.0.0/8', static function (): void {
    withRequest('198.51.100.99', '1.2.3.4', static function (): void {
        realIpCheck(real_ip() === '198.51.100.99', 'A direct client must never control the reported IP via the header');
    });
});

// 5. Invalid socket peer with no trusted proxy falls back to loopback.
withTrustedProxies(null, static function (): void {
    withRequest('not-an-ip', null, static function (): void {
        realIpCheck(real_ip() === '127.0.0.1', 'An invalid socket peer falls back to loopback');
    });
});

// 6. A :port suffix on a forwarded IPv4 is stripped.
withTrustedProxies('10.0.0.0/8', static function (): void {
    withRequest('10.0.0.2', '198.51.100.23:5555', static function (): void {
        realIpCheck(real_ip() === '198.51.100.23', 'A port suffix on a forwarded IPv4 must be stripped');
    });
});

// 7. IPv6 trusted ranges are honoured.
withTrustedProxies('2001:db8::/32', static function (): void {
    withRequest('2001:db8::1', '2001:db8::99, 2001:cafe::5', static function (): void {
        realIpCheck(real_ip() === '2001:cafe::5', 'IPv6 trusted ranges must be honoured');
    });
});

// 8. Non-IP tokens in the forwarded header are dropped.
withTrustedProxies('10.0.0.0/8', static function (): void {
    withRequest('10.0.0.2', 'garbage, 198.51.100.40', static function (): void {
        realIpCheck(real_ip() === '198.51.100.40', 'Non-IP tokens in the forwarded header must be dropped');
    });
});

// 9. Trusted peer without a forwarded header falls back to the peer address.
withTrustedProxies('10.0.0.0/8', static function (): void {
    withRequest('10.0.0.5', null, static function (): void {
        realIpCheck(real_ip() === '10.0.0.5', 'A trusted proxy without a forwarded header falls back to the peer');
    });
});

// 10. A single trusted IP (no CIDR) is honoured.
withTrustedProxies('10.0.0.5', static function (): void {
    withRequest('10.0.0.5', '198.51.100.23', static function (): void {
        realIpCheck(real_ip() === '198.51.100.23', 'A single trusted IP (no CIDR) must be honoured');
    });
});

// 11. When every hop is trusted the socket peer is reported.
withTrustedProxies('10.0.0.0/8, 198.51.100.0/24', static function (): void {
    withRequest('10.0.0.5', '198.51.100.23, 198.51.100.24', static function (): void {
        realIpCheck(real_ip() === '10.0.0.5', 'When every hop is trusted the peer address is reported');
    });
});

echo "Trusted proxy real client IP tests passed\n";
