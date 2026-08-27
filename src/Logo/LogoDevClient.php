<?php

declare(strict_types=1);

namespace CVS\Logo;

/**
 * logo.dev API client: company-name -> domain resolution (Search API,
 * fallback path only — see bin/fetch_logos.php for why Yahoo's `website`
 * field is tried first) and domain -> logo bytes (image endpoint).
 *
 * Guardrails (mirrors CVS\Ai\ClaudeClient):
 *  - NEVER throws to the caller — every error path returns null.
 *  - Retries transient failures (429 / 5xx / timeout / network) with
 *    exponential backoff, capped by a total wall-clock budget. A real
 *    "no result" (empty Search API match list, or a 404 image) is NOT
 *    retried — that is a legitimate answer, not a transient failure.
 *  - Both API keys come from config/logo-dev.php (.env-backed); never logged.
 *
 * Network is behind the LogoDevTransport seam. Per plan decision, this
 * client's tests do NOT mock that seam — only the deterministic pieces
 * (isRetryableStatus(), backoffDelayMs()) are unit-tested, as pure static
 * functions requiring no transport at all.
 */
final class LogoDevClient
{
    /** @param array<string, mixed> $config  config/logo-dev.php contents */
    public function __construct(
        private readonly array $config,
        private readonly LogoDevTransport $transport,
    ) {}

    /**
     * Best-match domain for a company name, or null when the Search API
     * returns no usable candidate. Auto-picks the top result — no admin
     * review step (plan decision).
     */
    public function searchDomain(string $companyName): ?string
    {
        $apiKey = (string) ($this->config['search_api_key'] ?? '');
        $query  = trim($companyName);
        if ($apiKey === '' || $query === '') {
            return null;
        }

        $base = (string) ($this->config['search_base_url'] ?? 'https://api.logo.dev');
        $url  = $base . '/search?q=' . urlencode($query) . '&strategy=match';
        $headers = ['Authorization: Bearer ' . $apiKey];

        $body = $this->requestWithRetry($url, $headers);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        $first = $decoded[0] ?? null;
        $domain = is_array($first) ? ($first['domain'] ?? null) : null;

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * Raw logo image bytes for a domain, or null when logo.dev has no logo
     * for it (404) or the request ultimately fails.
     */
    public function fetchImageBytes(string $domain): ?string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return null;
        }

        $base   = (string) ($this->config['img_base_url'] ?? 'https://img.logo.dev');
        $format = (string) ($this->config['image_format'] ?? 'webp');
        $size   = (int) ($this->config['image_size'] ?? 128);
        $retina = (bool) ($this->config['retina'] ?? true);

        $query = http_build_query([
            'token'  => (string) ($this->config['img_api_key'] ?? ''),
            'format' => $format,
            'size'   => $size,
            'retina' => $retina ? 'true' : 'false',
        ]);

        // rawurlencode leaves '.', '-', '~', '_' untouched — a bare domain
        // like "airbnb.com" round-trips unchanged, while still guarding
        // against a stray '/' or space reaching the URL path.
        $url = $base . '/' . rawurlencode($domain) . '?' . $query;

        $body = $this->requestWithRetry($url, []);

        return $body !== null && $body !== '' ? $body : null;
    }

    /**
     * Shared GET-with-retry loop for both endpoints above.
     *
     * @param array<int, string> $headers
     */
    private function requestWithRetry(string $url, array $headers): ?string
    {
        $timeout    = (int) ($this->config['timeout'] ?? 20);
        $maxRetries = (int) ($this->config['max_retries'] ?? 2);
        $budget     = (float) ($this->config['total_timeout'] ?? 25);
        $baseDelay  = (int) ($this->config['retry_base_delay_ms'] ?? 500);

        $deadline = microtime(true) + $budget;
        $attempt  = 0;

        while (true) {
            $resp = $this->transport->get($url, $headers, $timeout);

            if ($resp['error'] !== null) {
                if ($attempt >= $maxRetries || !$this->sleepForRetry($baseDelay, $attempt, $deadline)) {
                    error_log('[Logo] transport error: ' . $resp['error']);
                    return null;
                }
                $attempt++;
                continue;
            }

            $status = $resp['status'];
            if ($status >= 200 && $status < 300) {
                return $resp['body'];
            }

            if (!self::isRetryableStatus($status) || $attempt >= $maxRetries) {
                if ($status !== 404) {
                    error_log('[Logo] api error status=' . $status);
                }
                return null;
            }

            if (!$this->sleepForRetry($baseDelay, $attempt, $deadline)) {
                return null;
            }
            $attempt++;
        }
    }

    /**
     * Sleeps for the backoff delay if there is still budget for another
     * attempt afterwards; returns false (no sleep performed) when the
     * budget would be exceeded, signalling the caller to give up.
     */
    private function sleepForRetry(int $baseDelayMs, int $attempt, float $deadline): bool
    {
        $delaySeconds = self::backoffDelayMs($attempt, $baseDelayMs) / 1000.0;
        if (microtime(true) + $delaySeconds >= $deadline) {
            return false;
        }
        if ($delaySeconds > 0.0) {
            usleep((int) ($delaySeconds * 1_000_000));
        }
        return true;
    }

    /** Pure — testable without a transport. Mirrors ClaudeClient's classification. */
    public static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status === 529 || in_array($status, [500, 502, 503, 504], true);
    }

    /** Pure — testable without a transport. delay = base * 2^attempt (ms). */
    public static function backoffDelayMs(int $attempt, int $baseDelayMs): int
    {
        return (int) ($baseDelayMs * (2 ** $attempt));
    }
}
