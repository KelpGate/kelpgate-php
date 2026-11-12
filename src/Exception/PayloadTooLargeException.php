<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

/** The file is larger than the plan allows (413), or larger than `maxBytes` set on the client. */
class PayloadTooLargeException extends KelpgateException
{
    /** @var string|null */
    private $upgradeUrl;

    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, ['code' => 'PAYLOAD_TOO_LARGE'] + $details);
        $this->upgradeUrl = isset($this->body['upgrade_url']) ? (string) $this->body['upgrade_url'] : null;
    }

    public function getUpgradeUrl(): ?string
    {
        return $this->upgradeUrl;
    }
}
