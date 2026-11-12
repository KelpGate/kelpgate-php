<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The service failed (5xx). */
class ServerException extends KelpgateException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, ['code' => 'SERVER_ERROR'] + $details);
    }
}
