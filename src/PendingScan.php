<?php

declare(strict_types=1);

namespace Kelpgate;

/** An asynchronous scan whose verdict is not ready yet. */
final class PendingScan
{
    /** @var string */
    public $scanId;

    /** @var string|null */
    public $requestId;

    public function __construct(string $scanId, ?string $requestId = null)
    {
        $this->scanId = $scanId;
        $this->requestId = $requestId;
    }
}
