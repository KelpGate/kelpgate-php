<?php

declare(strict_types=1);

namespace Kelpgate\Http;

/** A file on disk that goes out as the `file` part of a multipart request. */
final class Upload
{
    /** @var string */
    public $path;

    /** @var string */
    public $filename;

    /** @var bool Delete the file after the request: set when the client wrote it to a temp file. */
    public $temporary;

    public function __construct(string $path, string $filename, bool $temporary = false)
    {
        $this->path = $path;
        $this->filename = $filename;
        $this->temporary = $temporary;
    }
}
