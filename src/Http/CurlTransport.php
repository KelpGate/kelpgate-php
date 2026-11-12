<?php

declare(strict_types=1);

namespace Kelpgate\Http;

use Kelpgate\Exception\NetworkException;
use Kelpgate\Exception\TimeoutException;

/** The default transport, built on ext-curl. It streams uploads from disk and never follows redirects. */
final class CurlTransport implements Transport
{
    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new \Kelpgate\Exception\ConfigException(
                'ext-curl is not available: install it, or pass a custom transport to the client'
            );
        }
    }

    public function send(Request $request): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new NetworkException('Could not start a cURL handle');
        }

        $responseHeaders = [];
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($request->timeout, 10.0) * 1000),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
        } elseif (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($request->upload !== null) {
            $fields = $request->fields;
            $fields['file'] = new \CURLFile($request->upload->path, 'application/octet-stream', $request->upload->filename);
            $options[CURLOPT_POSTFIELDS] = $fields;
        } elseif ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException(sprintf('The request timed out after %s seconds', $request->timeout));
            }
            throw new NetworkException('The request failed: ' . ($error !== '' ? $error : 'cURL error ' . $errno));
        }

        return new Response($status, (string) $body, $responseHeaders);
    }
}
