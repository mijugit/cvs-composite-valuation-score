<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Production HttpTransport — the only place that performs real cURL to Anthropic.
 *
 * Mirrors the cURL conventions in FinancialDataFetcher. Never throws: a
 * connection/timeout failure is reported as `error` (non-null) with `status` 0,
 * so ClaudeClient can map it to a typed AiFailure.
 */
final class CurlTransport implements HttpTransport
{
    public function send(string $url, string $jsonBody, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
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
