<?php

declare(strict_types=1);

namespace CVS\LlmGemini;

use CVS\Ai\CacheableSystem;
use CVS\Ai\GeminiClient;
use CVS\Ai\GeminiClientFactory;
use DateTimeImmutable;

/**
 * Resolves, per candidate ticker, fresh news context for the Gemini wallet's
 * decision engine — always a live `googleSearch`-grounded call, never a
 * cached Claude-generated analysis (change: llm-gemini-wallet — the user's
 * explicit choice for full provider isolation, unlike the sibling
 * CVS\LlmFree\LlmFreeContextGatherer, which checks ai_analyses/
 * ai_critical_reviews freshness first).
 *
 * Bounded to the first $searchCap candidates (same cost-bounding lever as the
 * sibling module) — callers MUST pass $candidateTickers already ordered by
 * priority (e.g. CVS Swing descending); the cap applies to that order.
 */
class LlmGeminiContextGatherer
{
    /** Cost/time safety cap for the per-ticker search sub-call itself. */
    private const MAX_TOKENS = 1024;

    /** @param array<string, mixed> $geminiConfig config/gemini.php contents */
    public function __construct(
        private readonly array $geminiConfig,
        private readonly int $searchCap,
        private readonly ?GeminiClient $clientOverride = null,
    ) {}

    /**
     * @param  list<string>          $candidateTickers ordered by priority (highest CVS Swing first)
     * @return array<string, string> ticker => context text; absence means "no extra context available"
     */
    public function gather(array $candidateTickers): array
    {
        $context = [];
        $capped  = array_slice($candidateTickers, 0, max(0, $this->searchCap));
        if ($capped === []) {
            return $context;
        }

        $client = $this->clientOverride ?? GeminiClientFactory::fromConfig($this->geminiConfig);

        foreach ($capped as $ticker) {
            $ticker = strtoupper($ticker);
            $text   = $this->search($client, $ticker);
            if ($text !== null) {
                $context[$ticker] = $text;
            }
        }

        return $context;
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function search(GeminiClient $client, string $ticker): ?string
    {
        $result = $client->sendMessage(
            [['role' => 'user', 'content' => $this->buildUserMessage($ticker)]],
            $this->buildSystemPrompt(),
            [
                'max_tokens' => self::MAX_TOKENS,
                'tools'      => [['googleSearch' => new \stdClass()]],
            ]
        );

        if (!$result->ok || $result->text === null || trim($result->text) === '') {
            error_log("LlmGeminiContextGatherer: search failed for {$ticker}" . ($result->failureKind !== null ? ' — ' . $result->failureKind->value : ''));
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
}
