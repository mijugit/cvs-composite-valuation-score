<?php

declare(strict_types=1);

namespace CVS\Api\Sec;

/**
 * Fetches diluted share counts from the SEC's free XBRL API.
 *
 * No API key. The SEC asks only for a User-Agent naming who is calling and
 * limits callers to 10 requests a second; both are honoured here. Selection and
 * parsing live in SecFacts, which is pure — this class is the network and the
 * cache.
 *
 * Everything fails soft. A share count from the SEC is an improvement on the
 * Yahoo-derived figure, never a prerequisite: if the SEC is unreachable, slow,
 * or has nothing for a ticker, the caller keeps the number it already had.
 */
final class SecShareCountClient
{
    private const CIK_MAP_URL   = 'https://www.sec.gov/files/company_tickers.json';
    private const CONCEPT_URL   = 'https://data.sec.gov/api/xbrl/companyconcept/CIK%s/us-gaap/%s.json';

    /**
     * Share counts change once a quarter, so a long cache costs nothing in
     * accuracy and keeps a full rescore down to a handful of SEC calls.
     */
    private const CONCEPT_TTL = 7 * 86400;
    private const CIK_MAP_TTL = 30 * 86400;

    /** @var array<string, string>|null lazily loaded ticker => CIK */
    private ?array $cikMap = null;

    /** @var array<string, array{count: float, period_end: string}|null> per-run memo */
    private array $memo = [];

    public function __construct(
        private readonly string $userAgent,
        private readonly string $cacheDir,
        private readonly int    $timeoutSeconds = 20,
    ) {}

    /**
     * Diluted share count for a US filer, or null when unavailable.
     *
     * @return array{count: float, period_end: string}|null
     */
    public function dilutedShares(string $ticker): ?array
    {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '') {
            return null;
        }
        if (array_key_exists($ticker, $this->memo)) {
            return $this->memo[$ticker];
        }

        $result = null;
        $cik    = $this->cik($ticker);
        if ($cik !== null) {
            $json = $this->getJson(
                sprintf(self::CONCEPT_URL, $cik, SecFacts::CONCEPT),
                'concept_' . $cik,
                self::CONCEPT_TTL
            );
            $units = $json['units']['shares'] ?? null;
            if (is_array($units)) {
                $result = SecFacts::latestQuarterly($units);
            }
        }

        return $this->memo[$ticker] = $result;
    }

    private function cik(string $ticker): ?string
    {
        if ($this->cikMap === null) {
            $this->cikMap = SecFacts::parseCikMap(
                $this->getJson(self::CIK_MAP_URL, 'cik_map', self::CIK_MAP_TTL)
            );
        }

        return $this->cikMap[$ticker] ?? null;
    }

    /**
     * Cached GET returning decoded JSON, or an empty array on any failure.
     *
     * @return array<mixed>
     */
    private function getJson(string $url, string $cacheKey, int $ttl): array
    {
        $file = rtrim($this->cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'sec_' . $cacheKey . '.cache';

        if (is_readable($file) && (time() - (int) filemtime($file)) < $ttl) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $body = $this->get($url);
        if ($body === null) {
            // Serve stale rather than nothing: a share count from last month is
            // far better than falling back to a figure known to be 30% out.
            if (is_readable($file)) {
                $stale = json_decode((string) file_get_contents($file), true);
                if (is_array($stale)) {
                    return $stale;
                }
            }
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        @file_put_contents($file, $body, LOCK_EX);

        return $decoded;
    }

    private function get(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            // Required by SEC policy: identify the caller with a contact.
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => 'gzip',
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($body) || $body === '') {
            return null;
        }

        // Stay inside the SEC's 10 requests/second guidance.
        usleep(120000);

        return $body;
    }
}
