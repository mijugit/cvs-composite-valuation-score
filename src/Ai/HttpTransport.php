<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Narrow HTTP seam so ClaudeClient can be unit-tested fully offline.
 *
 * Production uses CurlTransport; tests inject a FakeTransport returning canned
 * responses. The contract never throws — connection/timeout failures are
 * reported as `error` (non-null) with `status` 0, so the client can map them to
 * a typed AiFailure rather than catching exceptions.
 */
interface HttpTransport
{
    /**
     * Execute a single POST request.
     *
     * @param array<int, string> $headers  Raw header lines, e.g. "x-api-key: ...".
     * @param int                 $timeout  Per-attempt timeout in seconds.
     * @return array{status: int, body: string, error: string|null}
     *         status: HTTP status (0 when the request never completed),
     *         body:   raw response body ('' on transport failure),
     *         error:  transport error message, or null on a completed HTTP response.
     */
    public function send(string $url, string $jsonBody, array $headers, int $timeout): array;
}
