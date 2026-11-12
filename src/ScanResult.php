<?php

declare(strict_types=1);

namespace Kelpgate;

use Kelpgate\Exception\InfectedException;

/** A finished scan. The properties are read-only by convention. */
final class ScanResult
{
    /** @var bool */
    public $infected;

    /** @var string "CLEAN" or "INFECTED". */
    public $status;

    /** @var string Detection name, or "OK" for a clean file. */
    public $signature;

    /** @var string|null */
    public $sha256;

    /** @var string[] Authors of the rules that matched, for rule-license attribution. */
    public $ruleAuthors;

    /** @var string|null */
    public $filename;

    /** @var string|null */
    public $scanId;

    /** @var string|null */
    public $timestamp;

    /** @var string|null */
    public $s3Path;

    /** @var string|null */
    public $downloadUrl;

    /** @var string|null */
    public $requestId;

    /** @var bool The file was refused by policy (type or content rules), not detected as malware. */
    public $policyRejected;

    /** @var bool An engine did not finish, so coverage was reduced. Consider treating the file with care. */
    public $degraded;

    /** @var array<string, mixed> The response body as received. */
    public $raw;

    /**
     * @param array<string, mixed> $data The decoded response body.
     */
    public function __construct(array $data, ?string $requestId = null)
    {
        $signature = isset($data['av-signature']) ? (string) $data['av-signature'] : '';
        $this->status = isset($data['av-status']) ? (string) $data['av-status'] : '';
        $this->infected = $this->status === 'INFECTED';
        $this->signature = $signature;
        $this->sha256 = isset($data['sha256']) ? (string) $data['sha256'] : null;
        $this->ruleAuthors = isset($data['rule_authors']) && is_array($data['rule_authors'])
            ? array_values(array_map('strval', $data['rule_authors']))
            : [];
        $this->filename = isset($data['filename']) ? (string) $data['filename'] : null;
        $this->scanId = isset($data['scan_id']) ? (string) $data['scan_id'] : null;
        $this->timestamp = isset($data['av-timestamp']) ? (string) $data['av-timestamp'] : null;
        $this->s3Path = isset($data['s3_path']) ? (string) $data['s3_path'] : null;
        $this->downloadUrl = isset($data['download_url']) ? (string) $data['download_url'] : null;
        $this->requestId = $requestId;
        $this->policyRejected = strpos($signature, 'POLICY:') === 0;
        $this->degraded = strpos($signature, 'scan coverage reduced') !== false;
        $this->raw = $data;
    }

    public function isClean(): bool
    {
        return !$this->infected;
    }

    /**
     * Returns the result if it is clean. Throws InfectedException if it is infected.
     *
     * @throws InfectedException
     */
    public function assertClean(): self
    {
        if ($this->infected) {
            throw new InfectedException($this);
        }

        return $this;
    }
}
