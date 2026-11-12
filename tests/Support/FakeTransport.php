<?php

declare(strict_types=1);

namespace Kelpgate\Tests\Support;

use Kelpgate\Http\Request;
use Kelpgate\Http\Response;
use Kelpgate\Http\Transport;

/** Replays queued responses (or throws queued exceptions) and records every request. */
final class FakeTransport implements Transport
{
    /** @var array<int, Response|\Throwable> */
    private $queue = [];

    /** @var Request[] */
    public $requests = [];

    /** @var string[] Contents of uploaded files, in request order. */
    public $uploaded = [];

    /** @param Response|\Throwable $item */
    public function push($item): self
    {
        $this->queue[] = $item;

        return $this;
    }

    /** @param array<string, mixed> $body */
    public function json(int $status, array $body = [], array $headers = []): self
    {
        return $this->push(new Response($status, (string) json_encode($body), $headers));
    }

    public function token(string $token = 'tok-1', int $expiresIn = 3600): self
    {
        return $this->json(200, ['access_token' => $token, 'expires_in' => $expiresIn]);
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        if ($request->upload !== null) {
            $this->uploaded[] = (string) file_get_contents($request->upload->path);
        }
        if ($this->queue === []) {
            throw new \LogicException('FakeTransport has no response queued for ' . $request->method . ' ' . $request->url);
        }
        $item = array_shift($this->queue);
        if ($item instanceof \Throwable) {
            throw $item;
        }

        return $item;
    }

    /** @return Request[] Requests that were not the token request. */
    public function apiRequests(): array
    {
        return array_values(array_filter($this->requests, static function (Request $r): bool {
            return strpos($r->url, '/oauth2/token') === false;
        }));
    }
}
