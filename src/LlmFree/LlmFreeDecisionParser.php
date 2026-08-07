<?php

declare(strict_types=1);

namespace CVS\LlmFree;

/**
 * Validates and normalises a raw LLM JSON response into decisions + legend.
 *
 * Unlike CVS\Portfolio\DecisionParser (bare decision array), this module's
 * single Claude call returns one combined JSON object:
 *   {"decisions": [...], "legend": "..."}
 * Per-item decision validation duplicates DecisionParser's rules rather than
 * reusing that class directly — the two modules' logic must not intertwine
 * (PRD FR-007 resolution) — but the validation *behavior* matches exactly,
 * including per-item resilience (one malformed decision doesn't discard the
 * batch).
 *
 * Stateless and side-effect-free — safe to instantiate per call. Throws
 * \InvalidArgumentException on any schema violation so the caller can treat
 * the entire response as a parse failure and trigger a retry.
 */
final class LlmFreeDecisionParser
{
    private const VALID_ACTIONS    = ['BUY', 'SELL', 'HOLD', 'NO_ACTION'];
    private const REASON_MAX_CHARS = 500;

    public function __construct(private readonly int $legendMaxChars = 4000) {}

    /**
     * @return array{decisions: array<int, array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}>, legend: string}
     * @throws \InvalidArgumentException on any schema violation
     */
    public function parse(string $rawResponse): array
    {
        $clean = $this->stripMarkdownFences(trim($rawResponse));

        $decoded = json_decode($clean, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            $decoded = $this->extractJsonObject($clean);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(
                'LLM response is not a valid JSON object. JSON error: ' . json_last_error_msg() .
                ' | First 200 chars: ' . substr($clean, 0, 200)
            );
        }

        if (!array_key_exists('legend', $decoded) || !is_string($decoded['legend']) || trim($decoded['legend']) === '') {
            throw new \InvalidArgumentException(
                'LLM response is missing a non-empty string "legend" field.'
            );
        }

        $legend = mb_substr(trim($decoded['legend']), 0, $this->legendMaxChars);

        $rawDecisions = $decoded['decisions'] ?? null;
        if (!is_array($rawDecisions)) {
            throw new \InvalidArgumentException(
                'LLM response is missing a "decisions" array field.'
            );
        }

        if (count($rawDecisions) === 0) {
            throw new \InvalidArgumentException(
                'LLM response has an empty "decisions" array. Use [{action:NO_ACTION,...}] to signal no trades.'
            );
        }

        /** @var array<string, int> $seen ticker => index in $result */
        $seen   = [];
        $result = [];

        foreach ($rawDecisions as $index => $item) {
            if (!is_array($item)) {
                error_log("LlmFreeDecisionParser: item at index {$index} is not an object; skipped.");
                continue;
            }

            try {
                $validated = $this->validateItem($item, (int) $index);
            } catch (\InvalidArgumentException $e) {
                error_log("LlmFreeDecisionParser: skipping invalid decision at index {$index}: " . $e->getMessage());
                continue;
            }

            $key = $validated['ticker'] ?? '__no_action__';

            if (isset($seen[$key])) {
                error_log("LlmFreeDecisionParser: duplicate ticker '{$key}' at index {$index}; last entry wins.");
                $result[$seen[$key]] = $validated;
            } else {
                $seen[$key] = count($result);
                $result[]   = $validated;
            }
        }

        if (count($result) === 0) {
            throw new \InvalidArgumentException(
                'No valid decisions after validation — every item was malformed.'
            );
        }

        return ['decisions' => $result, 'legend' => $legend];
    }

    /**
     * @param array<mixed> $item
     * @return array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}
     * @throws \InvalidArgumentException
     */
    private function validateItem(array $item, int $index): array
    {
        if (!array_key_exists('action', $item) || !is_string($item['action'])) {
            throw new \InvalidArgumentException(
                "Decision at index {$index} is missing a string 'action' field."
            );
        }

        $action = strtoupper(trim($item['action']));

        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                "Decision at index {$index} has unknown action '{$action}'. Allowed: " . implode(', ', self::VALID_ACTIONS)
            );
        }

        $ticker = isset($item['ticker']) && is_string($item['ticker']) && trim($item['ticker']) !== ''
            ? strtoupper(trim($item['ticker']))
            : null;

        if (in_array($action, ['BUY', 'SELL', 'HOLD'], true) && $ticker === null) {
            throw new \InvalidArgumentException(
                "Decision at index {$index} with action '{$action}' requires a non-empty 'ticker'."
            );
        }

        if (in_array($action, ['BUY', 'SELL'], true)) {
            $rawQty   = isset($item['quantity']) ? (int) $item['quantity'] : null;
            $quantity = ($rawQty !== null && $rawQty > 0) ? $rawQty : null;
            if ($quantity === null) {
                throw new \InvalidArgumentException(
                    "Decision at index {$index} with action '{$action}' requires a positive integer 'quantity'."
                );
            }
        } else {
            $quantity = null;
        }

        $priceUsd = isset($item['price_usd']) && is_numeric($item['price_usd'])
            ? (float) $item['price_usd']
            : null;

        $reason = isset($item['reason']) && is_string($item['reason'])
            ? mb_substr($item['reason'], 0, self::REASON_MAX_CHARS)
            : null;

        return [
            'ticker'    => $ticker,
            'action'    => $action,
            'quantity'  => $quantity,
            'price_usd' => $priceUsd,
            'reason'    => $reason,
        ];
    }

    /**
     * Attempts to locate and decode a JSON object embedded in free-text
     * (LLM reasoning before the JSON, or a truncated response).
     *
     * @return array<mixed>|null
     */
    private function extractJsonObject(string $text): ?array
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $end = strrpos($text, '}');

        if ($end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded   = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function stripMarkdownFences(string $text): string
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/i', '', $text) ?? $text;
        return trim($text);
    }
}
