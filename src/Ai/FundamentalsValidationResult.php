<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Value object returned by FundamentalsValidationService::validate().
 *
 * `ok = false` means a transport/parse-level failure (network error, timeout,
 * non-JSON response) — nothing usable came back at all. Once the response
 * parses as JSON, per-field outcomes are NOT failures: a field the model
 * couldn't find is recorded in `diff` with status='checked_no_data', not
 * treated as an error (per the partial-success decision — wasted progress is
 * worse than an incomplete diff).
 */
final class FundamentalsValidationResult
{
    /**
     * @param array<string, array{old: mixed, new: mixed, status: string}> $diff
     */
    private function __construct(
        public readonly bool   $ok,
        public readonly array  $diff,
        public readonly string $notes,
        public readonly ?string $model,
        public readonly ?string $failureMessage,
    ) {
    }

    /**
     * @param array<string, array{old: mixed, new: mixed, status: string}> $diff
     */
    public static function success(array $diff, string $notes, string $model): self
    {
        return new self(true, $diff, $notes, $model, null);
    }

    public static function failure(string $message): self
    {
        return new self(false, [], '', null, $message);
    }
}
