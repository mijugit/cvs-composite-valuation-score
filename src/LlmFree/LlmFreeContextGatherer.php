<?php

declare(strict_types=1);

namespace CVS\LlmFree;

use CVS\Ai\AiAnalysisRepository;
use CVS\Ai\AiCriticalReviewRepository;
use CVS\Ai\CacheableSystem;
use CVS\Ai\ClaudeClient;
use CVS\Ai\ClaudeClientFactory;
use DateTimeImmutable;

/**
 * Resolves, per candidate ticker, whatever extra context (beyond raw CVS
 * numbers) is available for the decision engine — reusing an existing
 * stage-1/stage-2 analysis when fresh, or a bounded fresh web search when not.
 *
 * Freshness is checked first (zero-cost — table reads only) via the same
 * AiAnalysisRepository::isFresh() / AiCriticalReviewRepository::isFresh()
 * checks the rest of the app already uses. Only the subset still missing
 * fresh context triggers a live Claude call, and only up to $searchCap of
 * them — the lever that keeps the ~$0.50/cycle guardrail a mathematical
 * property of config rather than a runtime check.
 *
 * Callers MUST pass $candidateTickers already ordered by priority (e.g. CVS
 * Swing descending) — the cap applies to that order, not to any ranking done
 * here.
 */
class LlmFreeContextGatherer
{
    /** Cost/time safety cap for the per-ticker search sub-call itself. */
    private const MAX_TOKENS      = 1024;
    private const MAX_SEARCH_USES = 2;

    /** @param array<string, mixed> $aiConfig config/ai.php contents */
    public function __construct(
        private readonly AiAnalysisRepository       $analysisRepo,
        private readonly AiCriticalReviewRepository $reviewRepo,
        private readonly array                      $aiConfig,
        private readonly int                        $searchCap,
        private readonly ?ClaudeClient               $clientOverride = null,
    ) {}

    /**
     * @param  list<string>          $candidateTickers ordered by priority (highest CVS Swing first)
     * @return array<string, string> ticker => context text; absence means "no extra context available"
     */
    public function gather(array $candidateTickers): array
    {
        $context     = [];
        $needsSearch = [];

        foreach ($candidateTickers as $ticker) {
            $ticker = strtoupper($ticker);

            if ($this->reviewRepo->isFresh($ticker)) {
                $row = $this->reviewRepo->findByTicker($ticker);
                if ($row !== null && isset($row['content'])) {
                    $context[$ticker] = "Krytyczna recenzja (świeża):\n" . (string) $row['content'];
                    continue;
                }
            }

            if ($this->analysisRepo->isFresh($ticker)) {
                $row = $this->analysisRepo->findByTicker($ticker);
                if ($row !== null && isset($row['content'])) {
                    $context[$ticker] = "Analiza AI (świeża):\n" . (string) $row['content'];
                    continue;
                }
            }

            $needsSearch[] = $ticker;
        }

        $capped = array_slice($needsSearch, 0, max(0, $this->searchCap));
        if ($capped === []) {
            return $context;
        }

        $client = $this->clientOverride ?? ClaudeClientFactory::fromConfig($this->searchConfig());

        foreach ($capped as $ticker) {
            $text = $this->search($client, $ticker);
            if ($text !== null) {
                $context[$ticker] = $text;
            }
        }

        return $context;
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function search(ClaudeClient $client, string $ticker): ?string
    {
        $result = $client->sendMessage(
            [['role' => 'user', 'content' => $this->buildUserMessage($ticker)]],
            $this->buildSystemPrompt(),
            [
                'max_tokens' => self::MAX_TOKENS,
                'tools'      => [[
                    'type'     => 'web_search_20260209',
                    'name'     => 'web_search',
                    'max_uses' => self::MAX_SEARCH_USES,
                ]],
            ]
        );

        if (!$result->ok || $result->text === null || trim($result->text) === '') {
            error_log("LlmFreeContextGatherer: search failed for {$ticker}" . ($result->failureKind !== null ? ' — ' . $result->failureKind->value : ''));
            return null;
        }

        return $result->text;
    }

    private function buildUserMessage(string $ticker): string
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        return <<<MSG
TODAY'S DATE: {$today}

COMPANY: {$ticker}

Search for material recent news about this company from the last ~14 days
(earnings, guidance, management changes, analyst rating changes, sector
events). Summarize in a few sentences, in Polish, citing a date for every
fact. If nothing material surfaces, say so plainly — a quiet news cycle is
itself a useful data point.
MSG;
    }

    private function buildSystemPrompt(): CacheableSystem
    {
        $text = <<<'SYSTEM'
You are a research assistant preparing a brief, factual news summary for an
autonomous portfolio manager. Use web search to find recent context for the
requested ticker. Be concise (a few sentences), cite dates, and do not offer
investment recommendations — only report what you found. Write in Polish.
SYSTEM;

        return new CacheableSystem($text, CacheableSystem::TTL_5M);
    }

    /** @return array<string, mixed> */
    private function searchConfig(): array
    {
        $extra = is_array($this->aiConfig['critical_review'] ?? null) ? $this->aiConfig['critical_review'] : [];
        return array_merge($this->aiConfig, $extra);
    }
}
