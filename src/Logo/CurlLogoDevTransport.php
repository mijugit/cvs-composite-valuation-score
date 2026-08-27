<?php

declare(strict_types=1);

namespace CVS\Logo;

/**
 * Production LogoDevTransport — the only place that performs real cURL to
 * logo.dev. Mirrors CVS\Ai\CurlTransport's conventions, adapted to GET.
 * Never throws: a connection/timeout failure is reported as `error`
 * (non-null) with `status` 0.
 */
final class CurlLogoDevTransport implements LogoDevTransport
{
    public function get(string $url, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'cURL request failed'];
        }

        return ['status' => $status, 'body' => (string) $body, 'error' => null];
    }
}
