<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** Too many scans at once for this tenant (429). */
class RateLimitedException extends KelpgateException
{
    /** @var int|null */
    private $retryAfterSeconds;

    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, ['code' => 'RATE_LIMITED'] + $details);
        $this->retryAfterSeconds = isset($details['retryAfterSeconds']) ? (int) $details['retryAfterSeconds'] : null;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
