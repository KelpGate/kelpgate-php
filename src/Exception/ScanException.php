<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The request was rejected as invalid (400), or the file could not be parsed (412). */
class ScanException extends KelpgateException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, $details + ['code' => 'SCAN_ERROR']);
    }
}
