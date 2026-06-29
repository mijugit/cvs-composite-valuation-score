<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use CVS\Ai\CacheableSystem;
use CVS\Ai\ClaudeClient;
use CVS\Ai\ClaudeClientFactory;

/**
 * LLM decision pipeline for virtual portfolio rebalancing.
 *
 * Builds the portfolio prompt, calls ClaudeClient with exactly 0 internal retries
 * (service-level retry policy owns the 2-attempt contract), parses the response
 * through DecisionParser, and writes the LLM audit record to rebalance_cycle.
 *
 * The caller (bin/portfolio-rebalance.php) acts on the returned result array:
 *   ok=true  → pass $decisions to PortfolioService::executeCycle()
 *   ok=false → mark cycle llm_failed, exit without touching the portfolio
 */
class DecisionService
{
    /**
     * @param array<string, mixed> $aiConfig        merged ai.php + portfolio.php['llm']
     * @param array<string, mixed> $portfolioConfig  portfolio.php
     */
    public function __construct(
        private readonly CycleRepository $cycleRepo,
        private readonly array           $aiConfig,
        private readonly array           $portfolioConfig,
        private readonly ?ClaudeClient   $clientOverride = null,
    ) {}

    /**
     * Generates portfolio decisions from the LLM.
     *
     * Runs up to 2 service-level attempts. After each failure the audit record
     * is written before returning, so it persists even if executeCycle() later rolls back.
     *
     * @param array<string, mixed>              $portfolioState  from PortfolioRepository::getCurrentState()
     * @param array<int, array<string, mixed>>  $holdings        from PortfolioRepository::getCurrentHoldings()
     * @param array<int, array<string, mixed>>  $screenerRows    from ScreenerRepository::findAllLatest()
     *
     * @return array{ok: bool, decisions: array<int, array<string, mixed>>, retryCount: int, rawResponse: string, failureKind: string|null}
     */
    public function generate(
        int   $cycleId,
        array $portfolioState,
        array $holdings,
        array $screenerRows,
    ): array {
        $client       = $this->clientOverride ?? ClaudeClientFactory::fromConfig($this->aiConfig);
        $system       = new CacheableSystem($this->buildSystemPrompt(), CacheableSystem::TTL_5M);
        $userMessage  = $this->buildDataBlock($portfolioState, $holdings, $screenerRows);
        $retryDelay   = (int) ($this->portfolioConfig['llm']['retry_delay_seconds'] ?? 2);

        $lastRaw     = '';
        $failureKind = null;
        $decisions   = [];
        $ok          = false;
        $attempt     = 0;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result  = $client->sendMessage([['role' => 'user', 'content' => $userMessage]], $system);
            $lastRaw = $result->text ?? ($result->failureKind !== null ? $result->failureKind->value : 'unknown');

            if ($result->ok && $result->text !== null) {
                try {
                    $decisions   = (new DecisionParser())->parse($result->text);
                    $lastRaw     = $result->text;
                    $ok          = true;
                    $failureKind = null;
                    break;
                } catch (\InvalidArgumentException) {
                    $failureKind = 'parse_error';
                    $lastRaw     = $result->text;
                }
            } else {
                $failureKind = $result->failureKind !== null ? $result->failureKind->value : 'unknown';
            }

            // Sleep before retry (only between attempt 0 and 1)
            if ($attempt === 0 && $retryDelay > 0) {
                sleep($retryDelay);
            }
        }
        // $attempt is 2 after loop exhaustion (no break), or the value at break time.
        // Normalise to "how many retries were triggered" (0 = first attempt succeeded, 1 = retry needed).
        $retryCount = min($attempt, 1);

        // Write audit record before returning — must persist even if executeCycle() rolls back.
        $this->cycleRepo->updateLlmRecord(
            $cycleId,
            $retryCount,
            $lastRaw,
            $ok ? null : $failureKind,
            $ok ? $lastRaw : null,
        );

        return [
            'ok'          => $ok,
            'decisions'   => $decisions,
            'retryCount'  => $retryCount,
            'rawResponse' => $lastRaw,
            'failureKind' => $ok ? null : $failureKind,
        ];
    }

    // -----------------------------------------------------------------------
    // Private: prompt building
    // -----------------------------------------------------------------------

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Jesteś autonomicznym zarządcą wirtualnego portfela akcji opartego na modelu CVS (Composite Valuation Score).

TWOJE ZADANIE:
Otrzymujesz aktualny stan portfela oraz sygnały ze screenera CVS. Na tej podstawie generujesz decyzje rebalansowania.

ZASADY BEZWZGLĘDNE:
1. Odpowiadasz WYŁĄCZNIE poprawnym JSON array — bez żadnego tekstu przed ani po tablicy.
2. Każdy element tablicy musi zawierać pola: action, ticker, quantity, reason.
3. Dozwolone wartości action: BUY, SELL, HOLD, NO_ACTION.
4. Gdy nie zlecasz żadnych transakcji, zwróć: [{"action":"NO_ACTION","ticker":null,"quantity":null,"reason":"..."}]
5. NIE zwracaj pustej tablicy []. Zawsze zwróć co najmniej jeden element.
6. Pole reason maksymalnie 400 znaków — zwięzłe uzasadnienie po polsku.

OGRANICZENIA PORTFELA:
- Portfel jest long-only (tylko pozycje długie, brak krótkiej sprzedaży).
- Gotówka podana w wiadomości użytkownika to twój jedyny budżet na zakupy.
- Jeśli chcesz kupić kilka spółek ale gotówki nie starczy, rankinguj je od najważniejszej — system wykona zakupy po kolei aż skończy się gotówka.
- Quantity to liczba całkowita akcji (całe sztuki, brak ułamków).

FORMAT ODPOWIEDZI (przykład):
[
  {"action":"BUY","ticker":"AAPL","quantity":5,"reason":"Wysoki CVS swing, mocny sygnał golden"},
  {"action":"SELL","ticker":"MSFT","quantity":10,"reason":"CVS spadł poniżej progu, realizacja zysku"},
  {"action":"HOLD","ticker":"NVDA","quantity":null,"reason":"CVS stabilny, brak podstaw do zmiany"},
  {"action":"NO_ACTION","ticker":null,"quantity":null,"reason":"Brak atrakcyjnych kandydatów w obecnych warunkach"}
]

Pamiętaj: TYLKO JSON, zero komentarzy poza tablicą.
PROMPT;
    }

    /**
     * @param array<string, mixed>              $portfolioState
     * @param array<int, array<string, mixed>>  $holdings
     * @param array<int, array<string, mixed>>  $screenerRows
     */
    private function buildDataBlock(array $portfolioState, array $holdings, array $screenerRows): string
    {
        $cash    = number_format((float) ($portfolioState['cash'] ?? 0), 2, '.', '');
        $lines   = [];

        $lines[] = '=== STAN PORTFELA ===';
        $lines[] = "Dostępna gotówka: \${$cash} USD";
        $lines[] = '';

        if (empty($holdings)) {
            $lines[] = 'Aktualne pozycje: BRAK (portfel w całości w gotówce)';
        } else {
            $lines[] = 'Aktualne pozycje:';
            foreach ($holdings as $h) {
                $ticker   = (string) ($h['ticker'] ?? '');
                $qty      = (int) ($h['quantity'] ?? 0);
                $avgPrice = number_format((float) ($h['avg_entry_price'] ?? 0), 4, '.', '');
                $lines[]  = "  {$ticker}: {$qty} szt. @ avg \${$avgPrice}";
            }
        }

        $lines[] = '';
        $lines[] = '=== SYGNAŁY SCREENER CVS ===';
        $lines[] = 'Ticker | CVS Swing | CVS Fund | Reko Swing | Reko Fund | Sygnał   | Sektor                  | Cena USD';
        $lines[] = str_repeat('-', 110);

        foreach ($screenerRows as $row) {
            $ticker      = str_pad((string) ($row['ticker'] ?? ''), 6);
            $swing       = str_pad((string) ($row['cvs_swing'] ?? $row['cvs'] ?? '-'), 9);
            $fund        = str_pad((string) ($row['cvs_fund'] ?? '-'), 8);
            $recoSwing   = str_pad((string) ($row['reco_swing'] ?? $row['reco'] ?? '-'), 10);
            $recoFund    = str_pad((string) ($row['reco_fund'] ?? '-'), 9);
            $signal      = str_pad((string) ($row['golden_signal'] ?? '-'), 8);
            $sector      = str_pad(mb_substr((string) ($row['sector'] ?? '-'), 0, 22), 24);
            $price       = isset($row['price_at_snapshot']) && $row['price_at_snapshot'] !== null
                ? number_format((float) $row['price_at_snapshot'], 2, '.', '')
                : '-';

            $lines[] = "{$ticker} | {$swing} | {$fund} | {$recoSwing} | {$recoFund} | {$signal} | {$sector} | \${$price}";
        }

        $lines[] = '';
        $lines[] = '=== INSTRUKCJA ===';
        $lines[] = "Wygeneruj decyzje rebalansowania dla powyższych spółek.";
        $lines[] = "Budżet na zakupy: \${$cash} USD. Rankinguj BUY od najważniejszego.";
        $lines[] = 'Odpowiedz wyłącznie poprawnym JSON array zgodnie z formatem z instrukcji systemowej.';

        return implode("\n", $lines);
    }
}
