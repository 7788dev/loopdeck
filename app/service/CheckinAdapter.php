<?php

declare(strict_types=1);

namespace app\service;

/**
 * Contract between the check-in services and one platform adapter under
 * `extend/<platform>/`. Adapters return fixed, credential-free messages.
 */
interface CheckinAdapter
{
    /** Validate credentials and return a stable upstream identity and safe profile. */
    public function authenticate(array $credentials): array;

    /** Read-only account overview; must not perform the daily action. */
    public function profile(array $account): array;

    /** Execute a bounded step; checkpoint state contains no credentials. */
    public function execute(array $account, array $state = [], ?callable $checkpoint = null): array;

    public function credentials(): array;
}
