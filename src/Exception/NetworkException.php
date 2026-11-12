<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The service could not be reached, or the connection broke. */
class NetworkException extends KelpgateException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details + ['code' => 'NETWORK'], $previous);
    }
}
