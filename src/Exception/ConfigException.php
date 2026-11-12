<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The options passed to the client are wrong. Thrown by the constructor, before any request. */
class ConfigException extends KelpgateException
{
    public function __construct(string $message)
    {
        parent::__construct($message, ['code' => 'CONFIG']);
    }
}
