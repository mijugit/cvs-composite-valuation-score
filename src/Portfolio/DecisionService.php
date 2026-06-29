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
        $s = $this->portfolioConfig['strategy'] ?? [];

        $targetPos   = (int)   ($s['target_positions'] ?? 10);
        $minPos      = (int)   ($s['min_positions'] ?? 8);
        $maxPos      = (int)   ($s['max_positions'] ?? 12);
        $targetW     = (float) ($s['target_weight_pct'] ?? 10.0);
        $maxW        = (float) ($s['max_weight_pct'] ?? 15.0);
        $maxSector   = (float) ($s['max_sector_pct'] ?? 40.0);
        $minEmerging = (int)   ($s['min_emerging_positions'] ?? 2);
        $signal      = (string)($s['buy_signal'] ?? 'strong');
        $emLow       = (float) ($s['emerging_swing_low'] ?? 58.0);
        $emHigh      = (float) ($s['emerging_swing_high'] ?? 72.0);
        $sellBelow   = (float) ($s['sell_swing_below'] ?? 50.0);
        $takeProfit  = (float) ($s['take_profit_pct'] ?? 25.0);
        $stopLoss    = (float) ($s['stop_loss_pct'] ?? 15.0);

        $tpS  = rtrim(rtrim(number_format($takeProfit, 1, '.', ''), '0'), '.');
        $slS  = rtrim(rtrim(number_format($stopLoss, 1, '.', ''), '0'), '.');
        $tw   = rtrim(rtrim(number_format($targetW, 1, '.', ''), '0'), '.');
        $mw   = rtrim(rtrim(number_format($maxW, 1, '.', ''), '0'), '.');
        $msec = rtrim(rtrim(number_format($maxSector, 1, '.', ''), '0'), '.');
        $emLowS  = rtrim(rtrim(number_format($emLow, 1, '.', ''), '0'), '.');
        $emHighS = rtrim(rtrim(number_format($emHigh, 1, '.', ''), '0'), '.');
        $sellS   = rtrim(rtrim(number_format($sellBelow, 1, '.', ''), '0'), '.');
        $twFrac  = number_format($targetW / 100, 2, '.', '');

        return <<<PROMPT
Jesteś autonomicznym zarządcą wirtualnego portfela akcji, działającym na bazie
modelu CVS (Composite Valuation Score). Twoje decyzje są systematyczne, oparte
na sygnałach CVS i twardych regułach konstrukcji portfela — nie na intuicji.

═══════════════ METODOLOGIA CVS ═══════════════
CVS ocenia spółkę w skali 0–100 w dwóch horyzontach:
• CVS SWING (1–4 mies.): wycena 40% / momentum 45% / jakość 15% — TWÓJ GŁÓWNY horyzont
• CVS FUND  (6–12 mies.): wycena 65% / momentum 15% / jakość 20% — filtr jakości/wartości

Progi rekomendacji (te same dla swing i fund):
• ≥ 72  → SILNE KUPUJ      • 58–72 → AKUMULUJ
• 42–58 → NEUTRALNIE       • 28–42 → REDUKUJ      • < 28 → UNIKAJ

GOLDEN SIGNAL (próg 58 na obu wymiarach):
• strong    = swing ≥58 I fund ≥58  → momentum i wartość zgodne. JEDYNE źródło nowych zakupów.
• watchlist = fund ≥58, swing <58   → setup bez momentum. NIE kupuj (czekaj).
• momentum  = swing ≥58, fund <58   → drogie momentum. NIE kupuj (pułapka wartości).

Wszystkie spółki w danych przeszły już Quality Gate (rentowność, płynność) — są inwestowalne.

═══════════════ STRATEGIA (swing, 1–4 mies.) ═══════════════
KONSTRUKCJA PORTFELA:
• Cel: {$targetPos} pozycji (dopuszczalne {$minPos}–{$maxPos}).
• Waga docelowa ~{$tw}% wartości portfela na spółkę. TWARDY limit: {$mw}% na spółkę.
• TWARDY limit sektorowy: max {$msec}% wartości portfela w jednym sektorze.
• MINIMUM {$minEmerging} pozycje muszą pochodzić z pasma "emerging" (CVS Swing {$emLowS}–{$emHighS}) —
  to pretendenci do SILNE KUPUJ, łapani wcześnie. Nie buduj portfela wyłącznie z ≥{$emHighS}.

KIEDY KUPUJESZ (BUY):
• Tylko spółki z golden = {$signal}.
• Ranking: najpierw najwyższa konwikcja, ale respektuj limit sektorowy i minimum emerging.
• Quantity = część całkowita z ({$twFrac} × wartość_portfela / cena_USD).
• WAŻNE — droga spółka: jeśli ten wzór daje 0, ale cena 1 akcji ≤ {$mw}% wartości
  portfela, kup DOKŁADNIE 1 akcję. Jeśli nawet 1 akcja przekracza {$mw}% — POMIŃ spółkę
  (NIE zwracaj BUY z quantity 0; pomiń ją zupełnie lub daj NO_ACTION jeśli to jedyny ruch).
• LIMIT SEKTOROWY jest twardy: sumuj koszt zakupów (quantity × cena) w każdym sektorze
  i NIE przekraczaj {$msec}% wartości portfela na sektor. Jeśli kolejny zakup z sektora
  przebiłby limit — pomiń go i wybierz spółkę z innego sektora.
• Gotówka to budżet — rankinguj BUY od najważniejszego, system realizuje po kolei aż
  zabraknie gotówki.

KIEDY SPRZEDAJESZ (SELL) — dotyczy TYLKO spółek, które już masz w portfelu.
Każda pozycja w danych ma podany wynik P&L (zysk/strata vs cena wejścia).
Sprawdzaj reguły W TEJ KOLEJNOŚCI, pierwsza pasująca decyduje:
1. STOP-LOSS: P&L ≤ −{$slS}% → SELL całość. Ochrona kapitału, najwyższy priorytet
   (system i tak wymusi tę sprzedaż — zgłoś ją sam, by uzasadnienie było spójne).
2. TAKE-PROFIT: P&L ≥ +{$tpS}% → SELL, realizacja zysku. Wyjątek: jeśli spółka nadal
   ma bardzo mocny swing (≥{$emHighS}) i przyspiesza, możesz raz wstrzymać i dać HOLD.
3. CVS Swing spadł < {$sellS}, LUB reko_swing = REDUKUJ/UNIKAJ, LUB golden = momentum/null → SELL.
4. Pozycja przekroczyła {$mw}% portfela → przytnij do wagi docelowej (częściowy SELL).
• NIE odkupuj w tym samym cyklu spółki, którą właśnie sprzedajesz (zwłaszcza na take-profit).

KIEDY TRZYMASZ (HOLD):
• Żadna reguła SELL nie zaszła: P&L między −{$slS}% a +{$tpS}%, CVS Swing ≥ {$sellS},
  nadal strong/watchlist, waga w paśmie. Brak podstaw do zmiany.

═══════════════ FORMAT ODPOWIEDZI ═══════════════
Odpowiadasz WYŁĄCZNIE poprawnym JSON array — zero tekstu przed/po, zero markdown, zero ```.
Pola każdego elementu: action, ticker, quantity, reason.
• action ∈ {BUY, SELL, HOLD, NO_ACTION}
• quantity: liczba całkowita akcji dla BUY/SELL; null dla HOLD/NO_ACTION
• reason: max 400 znaków, po polsku, z KONKRETNYMI liczbami CVS uzasadniającymi decyzję
• Brak transakcji → [{"action":"NO_ACTION","ticker":null,"quantity":null,"reason":"..."}]
• Nigdy nie zwracaj pustej tablicy [].

PRZYKŁAD (wartości ilustracyjne):
[
  {"action":"BUY","ticker":"MU","quantity":12,"reason":"Swing 96 / Fund 93, strong dojrzały lider, sektor Technology. ~{$tw}% portfela."},
  {"action":"BUY","ticker":"ABNB","quantity":8,"reason":"Swing 61 / Fund 83, strong EMERGING — pretendent do SILNE KUPUJ, wczesne wejście."},
  {"action":"HOLD","ticker":"NVDA","quantity":null,"reason":"Swing 68 stabilny, strong, waga 11% w paśmie. Brak podstaw do zmiany."},
  {"action":"SELL","ticker":"INTC","quantity":40,"reason":"Swing spadł do 44 (REDUKUJ), golden=null. Wyjście z pozycji."}
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
        $s          = $this->portfolioConfig['strategy'] ?? [];
        $targetWPct = (float) ($s['target_weight_pct'] ?? 10.0);
        $buySignal  = (string)($s['buy_signal'] ?? 'strong');
        $emLow      = (float) ($s['emerging_swing_low'] ?? 58.0);
        $emHigh     = (float) ($s['emerging_swing_high'] ?? 72.0);

        $cashVal = (float) ($portfolioState['cash'] ?? 0);

        // Price map (ticker → latest snapshot price) for valuing current holdings.
        $priceMap = [];
        foreach ($screenerRows as $row) {
            $t = strtoupper((string) ($row['ticker'] ?? ''));
            if ($t !== '' && isset($row['price_at_snapshot'])) {
                $priceMap[$t] = (float) $row['price_at_snapshot'];
            }
        }

        // Value holdings at snapshot price (fallback: avg entry). Sum → total portfolio value.
        $heldTickers   = [];
        $holdingsValue = 0.0;
        foreach ($holdings as $h) {
            $t   = strtoupper((string) ($h['ticker'] ?? ''));
            $qty = (int) ($h['quantity'] ?? 0);
            $px  = $priceMap[$t] ?? (float) ($h['avg_entry_price'] ?? 0);
            $heldTickers[$t]  = true;
            $holdingsValue   += $qty * $px;
        }

        $totalValue   = $cashVal + $holdingsValue;
        $targetWeight = $totalValue * ($targetWPct / 100);

        $lines   = [];
        $lines[] = '=== STAN PORTFELA ===';
        $lines[] = 'Wartość portfela: $' . number_format($totalValue, 2, '.', '')
                 . ' (gotówka $' . number_format($cashVal, 2, '.', '')
                 . ' + pozycje $' . number_format($holdingsValue, 2, '.', '') . ')';
        $lines[] = 'Dostępna gotówka (budżet na zakupy): $' . number_format($cashVal, 2, '.', '');
        $lines[] = 'Docelowa waga jednej pozycji (~' . rtrim(rtrim(number_format($targetWPct, 1, '.', ''), '0'), '.')
                 . '%): $' . number_format($targetWeight, 2, '.', '');
        $lines[] = '';

        if (empty($holdings)) {
            $lines[] = 'Aktualne pozycje: BRAK (portfel w całości w gotówce)';
        } else {
            $lines[] = 'Aktualne pozycje (kandydaci do HOLD/SELL):';
            foreach ($holdings as $h) {
                $t        = strtoupper((string) ($h['ticker'] ?? ''));
                $qty      = (int) ($h['quantity'] ?? 0);
                $avgVal   = (float) ($h['avg_entry_price'] ?? 0);
                $avgPrice = number_format($avgVal, 4, '.', '');
                $px       = $priceMap[$t] ?? $avgVal;
                $val      = $qty * $px;
                $pctPort  = $totalValue > 0 ? ($val / $totalValue * 100) : 0.0;

                // Unrealized P&L vs entry — the input for stop-loss / take-profit rules.
                $pnlPct  = $avgVal > 0 ? (($px - $avgVal) / $avgVal * 100) : 0.0;
                $pnlPart = sprintf(' | P&L %+.1f%%', $pnlPct);

                // Attach live CVS signal for the held name if present in the screener.
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

                $lines[] = sprintf(
                    '  %s: %d szt. @ avg $%s | cena $%s | wart. $%s (%.1f%% portfela)%s%s',
                    $t, $qty, $avgPrice, number_format($px, 2, '.', ''),
                    number_format($val, 2, '.', ''), $pctPort, $pnlPart, $sigPart
                );
            }
        }

        // Candidate universe: golden = buy_signal, PLUS any held ticker (for SELL/HOLD context).
        $candidates = [];
        foreach ($screenerRows as $row) {
            $t          = strtoupper((string) ($row['ticker'] ?? ''));
            $isCandidate = (string) ($row['golden_signal'] ?? '') === $buySignal;
            if ($isCandidate || isset($heldTickers[$t])) {
                $candidates[] = $row;
            }
        }

        $lines[] = '';
        $lines[] = '=== KANDYDACI DO KUPNA (golden=' . $buySignal . ') + Twoje pozycje ===';
        $lines[] = 'Pasmo EMERGING = swing ' . rtrim(rtrim(number_format($emLow, 1, '.', ''), '0'), '.')
                 . '–' . rtrim(rtrim(number_format($emHigh, 1, '.', ''), '0'), '.')
                 . ' (pretendenci do SILNE KUPUJ).';
        $lines[] = 'Ticker | Swing | Fund  | Reko Swing | Sygnał    | Sektor                  | Cena USD | Pasmo';
        $lines[] = str_repeat('-', 108);

        if (empty($candidates)) {
            $lines[] = '(brak spółek z sygnałem ' . $buySignal . ' w bieżącym screenerze)';
        } else {
            foreach ($candidates as $row) {
                $ticker    = str_pad((string) ($row['ticker'] ?? ''), 6);
                $swingVal  = $row['cvs_swing'] ?? null;
                $swing     = str_pad((string) ($swingVal ?? '-'), 5);
                $fund      = str_pad((string) ($row['cvs_fund'] ?? '-'), 5);
                $recoSwing = str_pad((string) ($row['reco_swing'] ?? '-'), 10);
                $signal    = str_pad((string) ($row['golden_signal'] ?? 'null'), 9);
                $sector    = str_pad(mb_substr((string) ($row['sector'] ?? '-'), 0, 22), 24);
                $price     = isset($row['price_at_snapshot'])
                    ? number_format((float) $row['price_at_snapshot'], 2, '.', '')
                    : '-';
                $band = ($swingVal !== null && (float) $swingVal >= $emLow && (float) $swingVal < $emHigh)
                    ? 'EMERGING'
                    : ((float) ($swingVal ?? 0) >= $emHigh ? 'dojrzały' : '-');

                $lines[] = "{$ticker} | {$swing} | {$fund} | {$recoSwing} | {$signal} | {$sector} | \${$price} | {$band}";
            }
        }

        $lines[] = '';
        $lines[] = '=== INSTRUKCJA ===';
        $lines[] = 'Zarządź portfelem zgodnie ze strategią swing z instrukcji systemowej:';
        $lines[] = '- nowe BUY tylko z listy kandydatów (golden=' . $buySignal . '), rankinguj od najsilniejszego;';
        $lines[] = '- zapewnij wymagane minimum pozycji z pasma EMERGING;';
        $lines[] = '- respektuj twarde limity wagi pozycji i sektora;';
        $lines[] = '- dla obecnych pozycji zdecyduj HOLD/SELL wg reguł wyjścia;';
        $lines[] = '- quantity licz względem docelowej wagi $' . number_format($targetWeight, 2, '.', '') . ' i ceny spółki.';
        $lines[] = 'Odpowiedz wyłącznie poprawnym JSON array zgodnie z formatem z instrukcji systemowej.';

        return implode("\n", $lines);
    }

    /**
     * Find a screener row by ticker (case-insensitive). Returns null if absent.
     *
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
