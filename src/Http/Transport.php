<?php

declare(strict_types=1);

namespace Kelpgate\Http;

/**
 * Sends one HTTP request. The default is {@see CurlTransport}; implement this to route requests through
 * another HTTP layer (for example WordPress's `wp_remote_request`, or a PSR-18 client).
 *
 * An implementation must not follow redirects, and must throw `Kelpgate\Exception\TimeoutException` when
 * the timeout is reached and `Kelpgate\Exception\NetworkException` for any other connection failure.
 * HTTP error statuses are not exceptions: return them as a {@see Response}.
 */
interface Transport
{
    public function send(Request $request): Response;
}
