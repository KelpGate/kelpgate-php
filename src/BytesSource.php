<?php

declare(strict_types=1);

namespace Kelpgate;

/**
 * Wraps bytes held in memory so they can be passed where a file is expected. Created by
 * {@see KelpgateClient::scanBytes()}.
 *
 * @internal
 */
final class BytesSource
{
    /** @var string */
    public $bytes;

    public function __construct(string $bytes)
    {
        $this->bytes = $bytes;
    }
}
