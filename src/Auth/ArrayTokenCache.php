<?php

declare(strict_types=1);

namespace Kelpgate\Auth;

/** Keeps tokens in memory for the life of the PHP process. The default. */
final class ArrayTokenCache implements TokenCache
{
    /** @var array<string, array{token: string, expiresAt: int}> */
    private $items = [];

    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, string $token, int $expiresAt): void
    {
        $this->items[$key] = ['token' => $token, 'expiresAt' => $expiresAt];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
