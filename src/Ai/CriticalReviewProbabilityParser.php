<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Splits a critical-review response into its narrative, the trailing
 * bull/bear probability JSON block, and (as of the critical-review-openai
 * follow-up fix, 2026-08-25) the sources list — all mandated by
 * CriticalReviewPrompt's single trailing JSON block.
 *
 * `sources` is parsed from the SAME block deliberately, rather than relying
 * on each provider's own automatic citation mechanism: Claude's Messages API
 * citation attachment is a heuristic that fires reliably on text closely
 * reproducing a source, but not on heavily synthesized analytical text —
 * exactly what this prompt asks every provider to write — so it returned
 * zero citations for Claude despite genuinely using web search, while
 * Gemini's groundingMetadata and GPT's annotations happened to fire
 * reliably. Explicit, parseable text puts source extraction under our
 * control uniformly across all four providers instead of depending on four
 * different citation-API quirks. Callers should prefer this parsed list and
 * fall back to the provider's native AiResult::$citations only if this one
 * is empty (a model that forgot the `sources` field, not a provider that
 * genuinely found nothing).
 *
 * Mirrors FundamentalsValidationService::decodeJson()'s fence-stripping
 * approach, but the JSON here sits at the TAIL of a much larger free-text
 * response rather than being the entire response. Never throws: any parse
 * failure (missing block, malformed JSON, missing/non-numeric keys) degrades
 * gracefully to "the whole text is narrative, everything else empty/null" —
 * a formatting hiccup from the model must never cost the user the narrative
 * they already paid a PRO-usage unit for.
 */
final class CriticalReviewProbabilityParser
{
    /**
     * @return array{narrative: string, bull_probability: ?int, bear_probability: ?int, rationale: ?string, sources: list<array{url: string, title: string}>}
     */
    public static function parse(string $rawText): array
    {
        $fallback = [
            'narrative'        => trim($rawText),
            'bull_probability' => null,
            'bear_probability' => null,
            'rationale'        => null,
            'sources'          => [],
        ];

        // Find the LAST fenced ```json ... ``` block (or a bare trailing
        // {...} object as a fallback for a model that skips the fence).
        // Both patterns use lazy `.*?` anchored on the end of the string —
        // NOT a `[^{}]*` no-braces restriction — because the `sources` array
        // now nests its own {url, title} objects; the end-anchor is what
        // correctly selects the outermost closing brace via backtracking,
        // regardless of how many braces are nested inside.
        if (preg_match('/```json\s*(\{.*?\})\s*```\s*$/s', $rawText, $matches) === 1) {
            $jsonText = $matches[1];
            $narrative = substr($rawText, 0, (int) strpos($rawText, $matches[0]));
        } elseif (preg_match('/(\{.*?"bull_probability".*?\})\s*$/s', $rawText, $matches) === 1) {
            $jsonText = $matches[1];
            $narrative = substr($rawText, 0, (int) strpos($rawText, $matches[0]));
        } else {
            return $fallback;
        }

        $decoded = json_decode($jsonText, true);
        if (!is_array($decoded)) {
            return $fallback;
        }

        $bull = self::clampPercentage($decoded['bull_probability'] ?? null);
        $bear = self::clampPercentage($decoded['bear_probability'] ?? null);
        $rationale = is_string($decoded['rationale'] ?? null) ? trim((string) $decoded['rationale']) : null;
        $sources   = self::parseSources($decoded['sources'] ?? null);

        if ($bull === null && $bear === null && $rationale === null && $sources === []) {
            return $fallback;
        }

        return [
            'narrative'        => trim($narrative),
            'bull_probability' => $bull,
            'bear_probability' => $bear,
            'rationale'        => $rationale !== '' ? $rationale : null,
            'sources'          => $sources,
        ];
    }

    /**
     * @return list<array{url: string, title: string}>
     */
    private static function parseSources(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $byUrl = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['url']) || !is_string($entry['url']) || $entry['url'] === '') {
                continue;
            }
            $byUrl[$entry['url']] = [
                'url'   => $entry['url'],
                'title' => is_string($entry['title'] ?? null) && $entry['title'] !== '' ? $entry['title'] : $entry['url'],
            ];
        }

        return array_values($byUrl);
    }

    private static function clampPercentage(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) round((float) $value);
        return max(0, min(100, $int));
    }
}
