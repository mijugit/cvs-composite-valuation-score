<?php

declare(strict_types=1);

namespace CVS\Portfolio;

/**
 * Validates and normalises a raw LLM JSON response into a structured decision array.
 *
 * Stateless and side-effect-free — safe to instantiate per call.
 * Throws \InvalidArgumentException on any schema violation so the caller
 * can treat the entire response as a parse failure and trigger a retry.
 */
final class DecisionParser
{
    private const VALID_ACTIONS     = ['BUY', 'SELL', 'HOLD', 'NO_ACTION'];
    private const REASON_MAX_CHARS  = 500;

    /**
     * Parses and validates the raw LLM response string.
     *
     * @return array<int, array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}>
     * @throws \InvalidArgumentException on any schema violation
     */
    public function parse(string $rawResponse): array
    {
        $clean = $this->stripMarkdownFences(trim($rawResponse));

        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            $decoded = $this->extractJsonArray($clean);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(
                'LLM response is not a valid JSON array. JSON error: ' . json_last_error_msg() .
                ' | First 200 chars: ' . substr($clean, 0, 200)
            );
        }

        if (count($decoded) === 0) {
            throw new \InvalidArgumentException(
                'LLM response is an empty array. Use [{action:NO_ACTION,...}] to signal no trades.'
            );
        }

        /** @var array<string, int> $seen ticker → index in $result */
        $seen   = [];
        $result = [];

        foreach ($decoded as $index => $item) {
            // Per-item resilience: a single malformed decision (e.g. BUY with
            // quantity 0 because price > target weight) must NOT discard the whole
            // batch. Skip the bad item, keep the rest. Only a structurally broken
            // response (not JSON / not array / zero valid items) is a parse failure.
            if (!is_array($item)) {
                error_log("DecisionParser: item at index {$index} is not an object; skipped.");
                continue;
            }

            try {
                $validated = $this->validateItem($item, $index);
            } catch (\InvalidArgumentException $e) {
                error_log("DecisionParser: skipping invalid decision at index {$index}: " . $e->getMessage());
                continue;
            }

            $key = $validated['ticker'] ?? '__no_action__';

            if (isset($seen[$key])) {
                // Duplicate ticker — last entry wins; overwrite in-place.
                error_log("DecisionParser: duplicate ticker '{$key}' at index {$index}; last entry wins.");
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

        return $result;
    }

    /**
     * @param array<mixed> $item
     * @return array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}
     * @throws \InvalidArgumentException
     */
    private function validateItem(array $item, int $index): array
    {
        // --- action ---
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

        // --- ticker ---
        $ticker = isset($item['ticker']) && is_string($item['ticker']) && trim($item['ticker']) !== ''
            ? strtoupper(trim($item['ticker']))
            : null;

        if (in_array($action, ['BUY', 'SELL', 'HOLD'], true) && $ticker === null) {
            throw new \InvalidArgumentException(
                "Decision at index {$index} with action '{$action}' requires a non-empty 'ticker'."
            );
        }

        // --- quantity ---
        // Normalise: positive int only for BUY/SELL; HOLD/NO_ACTION always null
        // (LLMs sometimes emit 0 or a leftover quantity for non-trading actions —
        // we silently drop it rather than failing the whole cycle).
        if (in_array($action, ['BUY', 'SELL'], true)) {
            $rawQty   = isset($item['quantity']) ? (int) $item['quantity'] : null;
            $quantity = ($rawQty !== null && $rawQty > 0) ? $rawQty : null;
            if ($quantity === null) {
                throw new \InvalidArgumentException(
                    "Decision at index {$index} with action '{$action}' requires a positive integer 'quantity'."
                );
            }
        } else {
            // HOLD / NO_ACTION never carry a tradable quantity.
            $quantity = null;
        }

        // --- price_usd (optional, only relevant for BUY/SELL from future enhancements) ---
        $priceUsd = isset($item['price_usd']) && is_numeric($item['price_usd'])
            ? (float) $item['price_usd']
            : null;

        // --- reason (optional, truncated to max chars) ---
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
     * Attempts to locate and decode a JSON array embedded in free-text.
     *
     * Handles: (a) LLM reasoning text before the JSON, (b) truncated JSON
     * where max_tokens cut the response mid-object.
     *
     * @return array<mixed>|null
     */
    private function extractJsonArray(string $text): ?array
    {
        $start = strpos($text, '[');
        if ($start === false) {
            return null;
        }

        $end = strrpos($text, ']');

        if ($end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded   = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // JSON likely truncated — find the last complete object `}` and close the array.
        $fragment    = substr($text, $start);
        $lastBrace   = strrpos($fragment, '}');

        if ($lastBrace === false) {
            return null;
        }

        $repaired = substr($fragment, 0, $lastBrace + 1) . ']';
        $decoded  = json_decode($repaired, true);

        if (is_array($decoded) && count($decoded) > 0) {
            error_log('DecisionParser: recovered truncated JSON array (' . count($decoded) . ' items).');
            return $decoded;
        }

        return null;
    }

    private function stripMarkdownFences(string $text): string
    {
        // Remove optional ```json or ``` wrapper that some LLM responses include.
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/i', '', $text) ?? $text;
        return trim($text);
    }
}
