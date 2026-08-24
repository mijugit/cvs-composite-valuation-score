<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * The single source of truth for valid critical-review AI providers —
 * change: critical-review-models. Shared by the controller (input
 * validation), the repository (query scoping), and the view (tab rendering)
 * so the three layers cannot drift on what counts as a valid provider.
 */
final class CriticalReviewProvider
{
    public const CLAUDE = 'claude';
    public const GEMINI = 'gemini';

    /** @var list<string> */
    public const ALL = [self::CLAUDE, self::GEMINI];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
