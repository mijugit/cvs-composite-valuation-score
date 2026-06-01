<?php

declare(strict_types=1);

namespace CVS\Pro;

/**
 * Single decision point for PRO AI generation access.
 *
 * Reads the validated code from $_SESSION['pro_code'], checks it against
 * the database, and enforces daily/monthly limits from config/ai.php.
 *
 * Called by:
 *   - AnalysisController::show() to pass $canGenerateAi / $aiUsage to view
 *   - ProController::activate() to validate and cache the code
 *   - S-01 (future) before calling ClaudeClient
 */
class ProGate
{
    private const SESSION_KEY = 'pro_code';

    /** @param array<string, mixed> $config Full config/ai.php array */
    public function __construct(
        private readonly ProRepository      $proRepo,
        private readonly AiUsageRepository  $usageRepo,
        private readonly array              $config
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Validate a code and store it in session if valid.
     * Called by POST /pro/activate.
     */
    public function activateCode(string $code, int $userId): bool
    {
        if (trim($code) === '') {
            return false;
        }

        if (!$this->proRepo->findActiveCode($code, $userId)) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = $code;
        return true;
    }

    /**
     * True when the user has a valid cached code AND has not hit any limit.
     */
    public function canGenerate(int $userId): bool
    {
        return $this->hasValidCode($userId)
            && !$this->isOverDailyLimit($userId)
            && !$this->isOverMonthlyLimit($userId);
    }

    /**
     * Usage stats for the view — passed to templates as $aiUsage.
     *
     * @return array{today: int, month: int, daily_limit: int, monthly_limit: int}
     */
    public function getUsage(int $userId): array
    {
        return [
            'today'         => $this->usageRepo->countToday($userId),
            'month'         => $this->usageRepo->countThisMonth($userId),
            'daily_limit'   => $this->dailyLimit(),
            'monthly_limit' => $this->monthlyLimit(),
        ];
    }

    /**
     * Return the cached PRO code from session (or empty string if none).
     */
    public function getSessionCode(): string
    {
        return (string) ($_SESSION[self::SESSION_KEY] ?? '');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function hasValidCode(int $userId): bool
    {
        $code = $this->getSessionCode();
        if ($code === '') {
            return false;
        }
        return $this->proRepo->findActiveCode($code, $userId);
    }

    private function isOverDailyLimit(int $userId): bool
    {
        return $this->usageRepo->countToday($userId) >= $this->dailyLimit();
    }

    private function isOverMonthlyLimit(int $userId): bool
    {
        return $this->usageRepo->countThisMonth($userId) >= $this->monthlyLimit();
    }

    private function dailyLimit(): int
    {
        return (int) ($this->config['pro']['daily_limit'] ?? 10);
    }

    private function monthlyLimit(): int
    {
        return (int) ($this->config['pro']['monthly_limit'] ?? 100);
    }
}
