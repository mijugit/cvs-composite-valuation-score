<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Splits a critical-review response into its narrative and the trailing
 * bull/bear probability JSON block mandated by CriticalReviewPrompt —
 * change: critical-review-models.
 *
 * Mirrors FundamentalsValidationService::decodeJson()'s fence-stripping
 * approach, but the JSON here sits at the TAIL of a much larger free-text
 * response rather than being the entire response. Never throws: any parse
 * failure (missing block, malformed JSON, missing/non-numeric keys) degrades
 * gracefully to "the whole text is narrative, all probability fields null" —
 * a formatting hiccup from the model must never cost the user the narrative
 * they already paid a PRO-usage unit for.
 */
final class CriticalReviewProbabilityParser
{
    /**
     * @return array{narrative: string, bull_probability: ?int, bear_probability: ?int, rationale: ?string}
     */
    public static function parse(string $rawText): array
    {
        $fallback = [
            'narrative'        => trim($rawText),
            'bull_probability' => null,
            'bear_probability' => null,
            'rationale'        => null,
        ];

        // Find the LAST fenced ```json ... ``` block (or a bare trailing
        // {...} object as a fallback for a model that skips the fence).
        if (preg_match('/```json\s*(\{.*?\})\s*```\s*$/s', $rawText, $matches) === 1) {
            $jsonText = $matches[1];
            $narrative = substr($rawText, 0, (int) strpos($rawText, $matches[0]));
        } elseif (preg_match('/(\{[^{}]*"bull_probability"[^{}]*\})\s*$/s', $rawText, $matches) === 1) {
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

        if ($bull === null && $bear === null && $rationale === null) {
            return $fallback;
        }

        return [
            'narrative'        => trim($narrative),
            'bull_probability' => $bull,
            'bear_probability' => $bear,
            'rationale'        => $rationale !== '' ? $rationale : null,
        ];
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
