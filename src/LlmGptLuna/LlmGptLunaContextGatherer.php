<?php

declare(strict_types=1);

namespace CVS\LlmGptLuna;

use CVS\Ai\CacheableSystem;
use CVS\Ai\GPTClient;
use CVS\Ai\GPTClientFactory;
use DateTimeImmutable;

/**
 * Resolves, per candidate ticker, fresh news context for the GPT-Luna
 * wallet's decision engine — always a live `web_search`-grounded call, never
 * a cached Claude-generated analysis (change: llm-gpt-luna-wallet, same
 * full-provider-isolation choice as the sibling CVS\LlmGemini\
 * LlmGeminiContextGatherer, which this class structurally clones).
 *
 * Bounded to the first $searchCap candidates (same cost-bounding lever as
 * both sibling modules) — callers MUST pass $candidateTickers already
 * ordered by priority (e.g. CVS Swing descending); the cap applies to that
 * order.
 */
class LlmGptLunaContextGatherer
{
    /** Cost/time safety cap for the per-ticker search sub-call itself. */
    private const MAX_TOKENS = 1024;

    /** @param array<string, mixed> $gptConfig config/gpt.php contents */
    public function __construct(
        private readonly array $gptConfig,
        private readonly int $searchCap,
        private readonly ?GPTClient $clientOverride = null,
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

        $client = $this->clientOverride ?? GPTClientFactory::fromConfig($this->gptConfig, 'luna');

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

    private function search(GPTClient $client, string $ticker): ?string
    {
        $result = $client->sendMessage(
            [['role' => 'user', 'content' => $this->buildUserMessage($ticker)]],
            $this->buildSystemPrompt(),
            [
                'max_tokens' => self::MAX_TOKENS,
                'tools'      => [['type' => 'web_search']],
            ]
        );

        if (!$result->ok || $result->text === null || trim($result->text) === '') {
            error_log("LlmGptLunaContextGatherer: search failed for {$ticker}" . ($result->failureKind !== null ? ' — ' . $result->failureKind->value : ''));
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
