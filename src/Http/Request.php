<?php

declare(strict_types=1);

namespace Kelpgate\Http;

/** One outgoing request. Header names are lower case. */
final class Request
{
    /** @var string */
    public $method;

    /** @var string */
    public $url;

    /** @var array<string, string> */
    public $headers;

    /** @var string|null A ready-made body (JSON or form data). Not used together with `$upload`. */
    public $body;

    /** @var Upload|null A file to send as multipart/form-data, together with `$fields`. */
    public $upload;

    /** @var array<string, string> Extra multipart form fields that go with `$upload`. */
    public $fields;

    /** @var float Seconds. */
    public $timeout;

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $fields
     */
    public function __construct(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?Upload $upload = null,
        array $fields = [],
        float $timeout = 60.0
    ) {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->upload = $upload;
        $this->fields = $fields;
        $this->timeout = $timeout;
    }
}
