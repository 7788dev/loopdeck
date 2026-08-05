<?php

declare(strict_types=1);

namespace douyin\sdk;

final class CookieSession
{
    /** @var array<string,string> */
    private array $cookies = [];

    /** @param array<string,mixed>|string $cookies */
    public function __construct(array|string $cookies = [])
    {
        $this->replace($cookies);
    }

    /** @param array<string,mixed>|string $cookies */
    public function replace(array|string $cookies): void
    {
        $this->cookies = [];
        $this->merge($cookies);
    }

    /** @param array<string,mixed>|string $cookies */
    public function merge(array|string $cookies): void
    {
        if (is_string($cookies)) {
            foreach (explode(';', $cookies) as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) === 2 && $pair[0] !== '') {
                    $this->cookies[$pair[0]] = $this->unquote($pair[1]);
                }
            }
            return;
        }

        foreach ($cookies as $name => $value) {
            if ($name === '' || $value === null) {
                continue;
            }
            $this->cookies[(string)$name] = (string)$value;
        }
    }

    /** @param array<int,string> $headers */
    public function capture(array $headers): void
    {
        foreach ($headers as $header) {
            $segments = explode(';', (string)$header);
            $pair = explode('=', trim((string)array_shift($segments)), 2);
            if (count($pair) !== 2 || $pair[0] === '') {
                continue;
            }

            $delete = $pair[1] === '';
            foreach ($segments as $segment) {
                $attribute = explode('=', trim($segment), 2);
                if (strcasecmp($attribute[0] ?? '', 'Max-Age') === 0 && (int)($attribute[1] ?? 0) <= 0) {
                    $delete = true;
                }
            }
            if ($delete) {
                unset($this->cookies[$pair[0]]);
                continue;
            }
            $this->cookies[$pair[0]] = $this->unquote($pair[1]);
        }
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return isset($this->cookies[$name]) && $this->cookies[$name] !== '';
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->cookies;
    }

    public function header(): string
    {
        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }

    public function authenticated(): bool
    {
        $hasSession = $this->has('sessionid') || $this->has('sessionid_ss') || $this->has('sid_tt');
        $hasUser = $this->has('uid_tt') || $this->has('uid_tt_ss');
        return $hasSession && $hasUser;
    }

    private function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
            return stripcslashes(substr($value, 1, -1));
        }
        return $value;
    }
}
