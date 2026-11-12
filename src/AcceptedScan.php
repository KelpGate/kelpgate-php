<?php

declare(strict_types=1);

namespace Kelpgate;

/** The service accepted an asynchronous scan. Use the scan ID to read the result later. */
final class AcceptedScan
{
    /** @var string */
    public $scanId;

    /** @var string|null */
    public $filename;

    /** @var string|null */
    public $message;

    /** @var string|null */
    public $requestId;

    public function __construct(string $scanId, ?string $filename = null, ?string $message = null, ?string $requestId = null)
    {
        $this->scanId = $scanId;
        $this->filename = $filename;
        $this->message = $message;
        $this->requestId = $requestId;
    }
}
