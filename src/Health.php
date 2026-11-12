<?php

declare(strict_types=1);

namespace Kelpgate;

/** The result of a health check. */
final class Health
{
    /** @var bool */
    public $ok;

    /** @var int */
    public $status;

    /** @var string|null */
    public $signatureVersion;

    /** @var string|null */
    public $requestId;

    public function __construct(bool $ok, int $status, ?string $signatureVersion = null, ?string $requestId = null)
    {
        $this->ok = $ok;
        $this->status = $status;
        $this->signatureVersion = $signatureVersion;
        $this->requestId = $requestId;
    }
}
