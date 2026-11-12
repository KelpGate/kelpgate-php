<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/**
 * Base class for every exception this package throws on purpose.
 *
 * `getErrorCode()` is a stable string such as "QUOTA_EXCEEDED"; `getStatus()` is the HTTP status when
 * there was one.
 */
class KelpgateException extends \RuntimeException
{
    /** @var string */
    protected $errorCode = 'KELPGATE_ERROR';

    /** @var int|null */
    protected $status;

    /** @var string|null */
    protected $requestId;

    /** @var array<string, mixed> */
    protected $body = [];

    /**
     * @param array{code?: string, status?: int|null, requestId?: string|null, body?: array<string, mixed>} $details
     */
    public function __construct(string $message, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, isset($details['status']) ? (int) $details['status'] : 0, $previous);
        if (isset($details['code'])) {
            $this->errorCode = (string) $details['code'];
        }
        $this->status = isset($details['status']) ? (int) $details['status'] : null;
        $this->requestId = isset($details['requestId']) ? (string) $details['requestId'] : null;
        $this->body = $details['body'] ?? [];
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** @return array<string, mixed> The decoded response body, when the API sent one. */
    public function getBody(): array
    {
        return $this->body;
    }
}
