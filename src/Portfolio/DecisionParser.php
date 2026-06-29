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
            if (!is_array($item)) {
                throw new \InvalidArgumentException(
                    "Decision at index {$index} is not an object."
                );
            }

            $validated = $this->validateItem($item, $index);
            $key       = $validated['ticker'] ?? '__no_action__';

            if (isset($seen[$key])) {
                // Duplicate ticker — last entry wins; overwrite in-place.
                error_log("DecisionParser: duplicate ticker '{$key}' at index {$index}; last entry wins.");
                $result[$seen[$key]] = $validated;
            } else {
                $seen[$key] = count($result);
                $result[]   = $validated;
            }
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
        // Normalise: treat 0 and missing as null (LLMs sometimes emit 0 for HOLD/NO_ACTION).
        $rawQty   = isset($item['quantity']) ? (int) $item['quantity'] : null;
        $quantity = ($rawQty !== null && $rawQty > 0) ? $rawQty : null;

        if (in_array($action, ['BUY', 'SELL'], true)) {
            if ($quantity === null) {
                throw new \InvalidArgumentException(
                    "Decision at index {$index} with action '{$action}' requires a positive integer 'quantity'."
                );
            }
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

    private function stripMarkdownFences(string $text): string
    {
        // Remove optional ```json or ``` wrapper that some LLM responses include.
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/i', '', $text) ?? $text;
        return trim($text);
    }
}
