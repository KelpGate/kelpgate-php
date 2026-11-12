<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The request took longer than the client's timeout. */
class TimeoutException extends NetworkException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, ['code' => 'TIMEOUT'], $previous);
    }
}
