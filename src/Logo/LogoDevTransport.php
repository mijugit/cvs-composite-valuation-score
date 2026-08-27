<?php

declare(strict_types=1);

namespace CVS\Logo;

/**
 * Narrow HTTP seam so LogoDevClient's decision logic can be unit-tested
 * offline (see LogoDevClient's static isRetryableStatus()/backoffDelayMs()).
 *
 * Mirrors CVS\Ai\HttpTransport, but GET-only: both logo.dev endpoints this
 * client calls (Search API and the image endpoint) are GET, and their
 * response bodies — JSON text or raw image bytes — are both just PHP
 * strings, so one contract covers both. The contract never throws —
 * connection/timeout failures are reported as `error` (non-null) with
 * `status` 0.
 */
interface LogoDevTransport
{
    /**
     * Execute a single GET request.
     *
     * @param array<int, string> $headers Raw header lines, e.g. "Authorization: Bearer ...".
     * @param int                $timeout Per-attempt timeout in seconds.
     * @return array{status: int, body: string, error: string|null}
     *         status: HTTP status (0 when the request never completed),
     *         body:   raw response body ('' on transport failure),
     *         error:  transport error message, or null on a completed HTTP response.
     */
    public function get(string $url, array $headers, int $timeout): array;
}
