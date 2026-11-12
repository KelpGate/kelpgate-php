<?php

declare(strict_types=1);

namespace Kelpgate\Auth;

/**
 * Where access tokens are kept between requests. PHP normally starts fresh for every web request, so
 * the default in-memory cache fetches a token each time; give the client a persistent implementation
 * (APCu, a framework cache, a WordPress transient) to reuse one token until it expires.
 */
interface TokenCache
{
    /** @return array{token: string, expiresAt: int}|null A token and its expiry (Unix seconds), if one is cached. */
    public function get(string $key): ?array;

    public function set(string $key, string $token, int $expiresAt): void;

    public function delete(string $key): void;
}
