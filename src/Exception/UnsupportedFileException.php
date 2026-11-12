<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The file type is not allowed, or it is an encrypted archive (415). */
class UnsupportedFileException extends KelpgateException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, ['code' => 'UNSUPPORTED_FILE'] + $details);
    }
}
