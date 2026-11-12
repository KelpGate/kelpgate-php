<?php

declare(strict_types=1);

namespace Kelpgate\Exception;

use Kelpgate\ScanResult;

/** Thrown by `ScanResult::assertClean()` when a scan found malware. */
class InfectedException extends KelpgateException
{
    /** @var ScanResult */
    private $result;

    public function __construct(ScanResult $result)
    {
        parent::__construct(
            'File rejected: ' . ($result->signature !== '' ? $result->signature : 'infected'),
            ['code' => 'INFECTED', 'status' => 406, 'requestId' => $result->requestId]
        );
        $this->result = $result;
    }

    public function getResult(): ScanResult
    {
        return $this->result;
    }

    public function getSignature(): string
    {
        return $this->result->signature;
    }
}
