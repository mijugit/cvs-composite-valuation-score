<?php

declare(strict_types=1);

namespace CVS\LlmGemini;

use CVS\Ai\CacheableSystem;
use CVS\Ai\GeminiClient;
use CVS\Ai\GeminiClientFactory;
use CVS\LlmFree\LlmFreeDecisionParser;

/**
 * LLM decision pipeline for the Gemini wallet — structural clone of
 * CVS\LlmFree\LlmFreeDecisionService (change: llm-gemini-wallet), with
 * GeminiClient/GeminiClientFactory swapped in for ClaudeClient/ClaudeClientFactory.
 *
 * The system prompt (full interpretive discretion, no fixed thresholds, no
 * obligation to act in the wallet's interest, legend memory requirement) is
 * TEXTUALLY IDENTICAL to the sibling's — that sameness is the whole point of
 * the experiment (same instruction, different executor).
 *
 * Response parsing reuses CVS\LlmFree\LlmFreeDecisionParser unchanged — it
 * validates only the JSON shape {"decisions": [...], "legend": "..."}, which is
 * entirely provider-agnostic (deliberate reuse, not a duplicate, see plan.md
 * Key Discoveries).
 *
 * The caller (bin/llm-gemini-wallet-rebalance.php) acts on the returned result:
 *   ok=true  → pass $decisions to LlmGeminiService::executeCycle()
 *   ok=false → mark cycle llm_failed, exit without touching the wallet
 */
class LlmGeminiDecisionService
{
    /**
     * @param array<string, mixed> $geminiConfig merged config/gemini.php + llm-gemini-wallet.php['llm']
     * @param array<string, mixed> $walletConfig config/llm-gemini-wallet.php
     */
    public function __construct(
        private readonly LlmGeminiCycleRepository $cycleRepo,
        private readonly array                    $geminiConfig,
        private readonly array                    $walletConfig,
        private readonly ?GeminiClient             $clientOverride = null,
    ) {}

    /**
     * Generates wallet decisions + a legend entry from the LLM.
     *
     * Runs up to 2 service-level attempts. After each failure the audit
     * record is written before returning, so it persists even if
     * executeCycle() later rolls back.
     *
     * @param array<string, mixed>              $portfolioState  from LlmGeminiRepository::getCurrentState()
     * @param array<int, array<string, mixed>>  $holdings        from LlmGeminiRepository::getCurrentHoldings()
     * @param array<int, array<string, mixed>>  $screenerRows    candidate universe (CVS snapshot rows)
     * @param array<int, array{cycle_date: string, legend: string}> $legendHistory from LlmGeminiRepository::getLegendHistory()
     * @param array<string, string>             $contextByTicker from LlmGeminiContextGatherer::gather()
     *
     * @return array{ok: bool, decisions: array<int, array<string, mixed>>, legend: string|null, retryCount: int, rawResponse: string, failureKind: string|null}
     */
    public function generate(
        int   $cycleId,
        array $portfolioState,
        array $holdings,
        array $screenerRows,
        array $legendHistory,
        array $contextByTicker = [],
    ): array {
        $client      = $this->clientOverride ?? GeminiClientFactory::fromConfig($this->geminiConfig);
        $system      = new CacheableSystem($this->buildSystemPrompt(), (string) ($this->walletConfig['llm']['system_prompt_ttl'] ?? CacheableSystem::TTL_5M));
        $userMessage = $this->buildDataBlock($portfolioState, $holdings, $screenerRows, $legendHistory, $contextByTicker);
        $retryDelay  = (int) ($this->walletConfig['llm']['retry_delay_seconds'] ?? 2);
        $maxTokens   = (int) ($this->walletConfig['llm']['max_tokens'] ?? 6144);

        $lastRaw     = '';
        $failureKind = null;
        $decisions   = [];
        $legend      = null;
        $ok          = false;
        $attempt     = 0;
        $tokensIn    = 0;
        $tokensOut   = 0;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $client->sendMessage(
                [['role' => 'user', 'content' => $userMessage]],
                $system,
                ['max_tokens' => $maxTokens]
            );
            $lastRaw = $result->text ?? ($result->failureKind !== null ? $result->failureKind->value : 'unknown');

            if ($result->ok && $result->text !== null) {
                if ($result->usage !== null) {
                    $tokensIn  = $result->usage->inputTokens;
                    $tokensOut = $result->usage->outputTokens;
                }
                try {
                    $parsed      = (new LlmFreeDecisionParser((int) ($this->walletConfig['legend_max_chars'] ?? 4000)))->parse($result->text);
                    $decisions   = $parsed['decisions'];
                    $legend      = $parsed['legend'];
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

            if ($attempt === 0 && $retryDelay > 0) {
                sleep($retryDelay);
            }
        }

        $retryCount = min($attempt, 1);

        $this->cycleRepo->updateLlmRecord(
            $cycleId,
            $retryCount,
            $lastRaw,
            $ok ? null : $failureKind,
            $ok ? $lastRaw : null,
            $ok ? $legend : null,
            $tokensIn,
            $tokensOut,
        );

        return [
            'ok'          => $ok,
            'decisions'   => $decisions,
            'legend'      => $ok ? $legend : null,
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
Jesteś autonomicznym zarządcą wirtualnego portfela akcji. W przeciwieństwie do
klasycznego portfela systematycznego, TY interpretujesz sygnały modelu CVS
(Composite Valuation Score) — masz pełną swobodę interpretacyjną i prawo
kwestionować rekomendacje CVS, jeśli Twoja analiza wskazuje inaczej. Nie ma tu
twardych progów wagi pozycji, limitów sektorowych ani wymuszanego stop-lossu —
żaden mechanizm nie nadpisze Twojej decyzji. Jeśli podejmiesz decyzję, która
okaże się błędna, to Ty ponosisz jej konsekwencje w wyniku portfela — świadomie
przyjęte ryzyko jest częścią tego eksperymentu.

NIE MASZ OBOWIĄZKU działać na korzyść portfela. Możesz działać zachowawczo,
agresywnie, wbrew konsensusowi CVS, albo nie robić nic — decyzja należy w
całości do Ciebie. Jedyne twarde ograniczenia to fizyczne: nie możesz wydać
więcej gotówki niż masz, i nie możesz sprzedać więcej akcji niż posiadasz
(system przytnie taką próbę do faktycznie dostępnej ilości).

═══════════════ METODOLOGIA CVS (kontekst, nie nakaz) ═══════════════
CVS ocenia spółkę w skali 0–100 w dwóch horyzontach:
• CVS SWING (1–4 mies.): wycena 40% / momentum 45% / jakość 15%
• CVS FUND  (6–12 mies.): wycena 65% / momentum 15% / jakość 20%
Progi: ≥72 SILNE KUPUJ, 58–72 AKUMULUJ, 42–58 NEUTRALNIE, 28–42 REDUKUJ, <28 UNIKAJ.
GOLDEN SIGNAL: strong (oba ≥58), watchlist (fund≥58, swing<58), momentum
(swing≥58, fund<58). Wszystkie spółki w danych przeszły Quality Gate.
Traktuj to jako dane wejściowe do własnej analizy, nie jako regułę decyzyjną —
możesz kupić coś poza "strong", możesz trzymać coś z sygnałem "REDUKUJ", jeśli
Twoje uzasadnienie to obroni.

═══════════════ TWOJA LEGENDA (pamięć) ═══════════════
Poniżej w danych znajdziesz swoje własne wpisy z poprzednich cykli — Twoją
dotychczasową tezę inwestycyjną. To NIE jest ustalony fakt, który musisz
kontynuować — to punkt wyjścia do krytycznej rewizji. Nowy dzień, nowe newsy,
nowe przemyślenia: krytycznie zweryfikuj, czy teza z poprzednich wpisów nadal
się broni, czy wymaga korekty, czy któryś z argumentów już nie obowiązuje.

Musisz napisać NOWY wpis legendy w KAŻDYM cyklu — nawet jeśli Twoja teza się
nie zmienia. W takim wypadku pokaż, jaki inny aspekt rozważyłeś tym razem
(inny kątem spojrzenia, inny czynnik ryzyka, inna spółka), zamiast powtarzać
poprzedni wpis. Legenda ma czytać się jak rozumowanie inwestora, nie jak log
transakcji.

═══════════════ FORMAT ODPOWIEDZI ═══════════════
Odpowiadasz WYŁĄCZNIE poprawnym JSON obiektem — zero tekstu przed/po, zero
markdown, zero ```. Struktura:
{
  "decisions": [
    {"action":"BUY","ticker":"MU","quantity":12,"reason":"..."},
    {"action":"HOLD","ticker":"NVDA","quantity":null,"reason":"..."},
    {"action":"SELL","ticker":"INTC","quantity":40,"reason":"..."}
  ],
  "legend": "Twój wpis legendy na dziś — po polsku, rozumowanie inwestora."
}
• action ∈ {BUY, SELL, HOLD, NO_ACTION}
• quantity: liczba całkowita akcji dla BUY/SELL; null dla HOLD/NO_ACTION
• reason: max 400 znaków, po polsku, konkretne uzasadnienie tej decyzji
• Brak transakcji → "decisions":[{"action":"NO_ACTION","ticker":null,"quantity":null,"reason":"..."}]
• "legend" jest WYMAGANE w każdej odpowiedzi, niepuste, i musi zawierać
  Twoje rozumowanie — nie samo podsumowanie transakcji.
• Nigdy nie zwracaj pustej tablicy decisions.

Pamiętaj: TYLKO JSON, zero komentarzy poza obiektem.
PROMPT;
    }

    /**
     * @param array<string, mixed>              $portfolioState
     * @param array<int, array<string, mixed>>  $holdings
     * @param array<int, array<string, mixed>>  $screenerRows
     * @param array<int, array{cycle_date: string, legend: string}> $legendHistory
     * @param array<string, string>             $contextByTicker
     */
    private function buildDataBlock(
        array $portfolioState,
        array $holdings,
        array $screenerRows,
        array $legendHistory,
        array $contextByTicker,
    ): string {
        $cashVal = (float) ($portfolioState['cash'] ?? 0);

        $priceMap = [];
        foreach ($screenerRows as $row) {
            $t = strtoupper((string) ($row['ticker'] ?? ''));
            if ($t !== '' && isset($row['price_at_snapshot'])) {
                $priceMap[$t] = (float) $row['price_at_snapshot'];
            }
        }

        $holdingsValue = 0.0;
        foreach ($holdings as $h) {
            $t   = strtoupper((string) ($h['ticker'] ?? ''));
            $qty = (int) ($h['quantity'] ?? 0);
            $px  = $priceMap[$t] ?? (float) ($h['avg_entry_price'] ?? 0);
            $holdingsValue += $qty * $px;
        }
        $totalValue = $cashVal + $holdingsValue;

        $lines   = [];
        $lines[] = '=== STAN PORTFELA ===';
        $lines[] = 'Wartość portfela: $' . number_format($totalValue, 2, '.', '')
                 . ' (gotówka $' . number_format($cashVal, 2, '.', '')
                 . ' + pozycje $' . number_format($holdingsValue, 2, '.', '') . ')';
        $lines[] = 'Dostępna gotówka: $' . number_format($cashVal, 2, '.', '');
        $lines[] = '';

        if (empty($holdings)) {
            $lines[] = 'Aktualne pozycje: BRAK (portfel w całości w gotówce)';
        } else {
            $lines[] = 'Aktualne pozycje:';
            foreach ($holdings as $h) {
                $t        = strtoupper((string) ($h['ticker'] ?? ''));
                $qty      = (int) ($h['quantity'] ?? 0);
                $avgVal   = (float) ($h['avg_entry_price'] ?? 0);
                $px       = $priceMap[$t] ?? $avgVal;
                $val      = $qty * $px;
                $pctPort  = $totalValue > 0 ? ($val / $totalValue * 100) : 0.0;
                $pnlPct   = $avgVal > 0 ? (($px - $avgVal) / $avgVal * 100) : 0.0;

                $row     = $this->findRow($screenerRows, $t);
                $sigPart = $row !== null
                    ? sprintf(
                        ' | Swing %s / Fund %s | %s | golden=%s',
                        (string) ($row['cvs_swing'] ?? '-'),
                        (string) ($row['cvs_fund'] ?? '-'),
                        (string) ($row['reco_swing'] ?? '-'),
                        (string) ($row['golden_signal'] ?? 'null')
                    )
                    : ' | (brak aktualnego sygnału CVS w screenerze)';

                $ctxPart = isset($contextByTicker[$t]) ? "\n    Dodatkowy kontekst: " . $contextByTicker[$t] : '';

                $lines[] = sprintf(
                    '  %s: %d szt. @ avg $%s | cena $%s | wart. $%s (%.1f%% portfela) | P&L %+.1f%%%s%s',
                    $t, $qty, number_format($avgVal, 4, '.', ''), number_format($px, 2, '.', ''),
                    number_format($val, 2, '.', ''), $pctPort, $pnlPct, $sigPart, $ctxPart
                );
            }
        }

        // Bounded, sorted slice of the full universe — mirrors LlmFreeDecisionService's
        // guardrail (unfiltered dump blows past the cost budget and hung the sibling
        // module's live cron once, 2026-08-07).
        $maxCandidates = (int) ($this->walletConfig['max_candidates'] ?? 40);
        $candidates    = $screenerRows;
        usort($candidates, static function (array $a, array $b): int {
            $sa = isset($a['cvs_swing']) ? (float) $a['cvs_swing'] : -1.0;
            $sb = isset($b['cvs_swing']) ? (float) $b['cvs_swing'] : -1.0;
            return $sb <=> $sa;
        });
        $totalCandidates = count($candidates);
        if ($maxCandidates > 0 && $totalCandidates > $maxCandidates) {
            $candidates = array_slice($candidates, 0, $maxCandidates);
        }

        $lines[] = '';
        $lines[] = '=== KANDYDACI (dane CVS ze screenera) ===';
        if ($totalCandidates > count($candidates)) {
            $lines[] = sprintf('Pokazano %d najsilniejszych wg CVS Swing z %d spółek w pełnym screenerze.', count($candidates), $totalCandidates);
        }
        $lines[] = 'Ticker | Swing | Fund  | Reko Swing | Sygnał    | Sektor                  | Cena USD';
        $lines[] = str_repeat('-', 92);

        if (empty($candidates)) {
            $lines[] = '(brak spółek w bieżącym screenerze)';
        } else {
            foreach ($candidates as $row) {
                $ticker    = str_pad((string) ($row['ticker'] ?? ''), 6);
                $swing     = str_pad((string) ($row['cvs_swing'] ?? '-'), 5);
                $fund      = str_pad((string) ($row['cvs_fund'] ?? '-'), 5);
                $recoSwing = str_pad((string) ($row['reco_swing'] ?? '-'), 10);
                $signal    = str_pad((string) ($row['golden_signal'] ?? 'null'), 9);
                $sector    = str_pad(mb_substr((string) ($row['sector'] ?? '-'), 0, 22), 24);
                $price     = isset($row['price_at_snapshot'])
                    ? number_format((float) $row['price_at_snapshot'], 2, '.', '')
                    : '-';

                $t       = strtoupper((string) ($row['ticker'] ?? ''));
                $ctxPart = isset($contextByTicker[$t]) ? "\n    Dodatkowy kontekst: " . $contextByTicker[$t] : '';

                $lines[] = "{$ticker} | {$swing} | {$fund} | {$recoSwing} | {$signal} | {$sector} | \${$price}{$ctxPart}";
            }
        }

        $lines[] = '';
        $lines[] = '=== TWOJA LEGENDA — POPRZEDNIE WPISY (najnowszy pierwszy) ===';
        if (empty($legendHistory)) {
            $lines[] = '(brak wcześniejszych wpisów — to Twój pierwszy cykl)';
        } else {
            foreach ($legendHistory as $entry) {
                $lines[] = sprintf('  [%s] %s', $entry['cycle_date'], $entry['legend']);
            }
        }

        $lines[] = '';
        $lines[] = '=== INSTRUKCJA ===';
        $lines[] = 'Podejmij decyzje wg własnej analizy sygnałów CVS i kontekstu powyżej.';
        $lines[] = 'Krytycznie zrewiduj swoją dotychczasową tezę z wpisów legendy — nie kontynuuj jej bezrefleksyjnie.';
        $lines[] = 'Napisz nowy wpis legendy, nawet jeśli teza pozostaje bez zmian.';
        $lines[] = 'Odpowiedz wyłącznie poprawnym JSON obiektem zgodnie z formatem z instrukcji systemowej.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRow(array $rows, string $ticker): ?array
    {
        $needle = strtoupper($ticker);
        foreach ($rows as $row) {
            if (strtoupper((string) ($row['ticker'] ?? '')) === $needle) {
                return $row;
            }
        }
        return null;
    }
}
