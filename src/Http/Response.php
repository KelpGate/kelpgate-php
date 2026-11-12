<?php

declare(strict_types=1);

namespace Kelpgate\Http;

/** One HTTP response. Header names are lower case. */
final class Response
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    /** @var array<string, string> */
    public $headers;

    /** @param array<string, string> $headers */
    public function __construct(int $status, string $body = '', array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);

        return $this->headers[$name] ?? null;
    }
}
