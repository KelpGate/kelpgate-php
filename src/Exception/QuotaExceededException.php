<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The plan's monthly scan quota is used up (402). */
class QuotaExceededException extends KelpgateException
{
    /** @var int|null */
    private $quota;

    /** @var int|null */
    private $usage;

    /** @var string|null */
    private $upgradeUrl;

    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, ['code' => 'QUOTA_EXCEEDED'] + $details);
        $body = $this->body;
        $this->quota = isset($body['quota']) ? (int) $body['quota'] : null;
        // The service sends the count as `used`; `usage` is read as a fallback.
        if (isset($body['used'])) {
            $this->usage = (int) $body['used'];
        } elseif (isset($body['usage'])) {
            $this->usage = (int) $body['usage'];
        } else {
            $this->usage = null;
        }
        $this->upgradeUrl = isset($body['upgrade_url']) ? (string) $body['upgrade_url'] : null;
    }

    public function getQuota(): ?int
    {
        return $this->quota;
    }

    /** Scans used this month (the `used` field of the 402 response). */
    public function getUsage(): ?int
    {
        return $this->usage;
    }

    public function getUpgradeUrl(): ?string
    {
        return $this->upgradeUrl;
    }
}
