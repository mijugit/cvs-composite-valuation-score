---
id: s-05-dual-mode
status: ready
---

# Plan implementacji — S-05 CVS Dual Mode

## Kolejność wykonania

Zależności: 1 → 2 → 3 → 4 → 5 → (6, 7 równolegle) → 8 → 9 → 10

---

## Krok 1 — config/cvs-weights.php

Dodaj sekcję `modes`. Zachowaj istniejące klucze `benchmarks` i `data_source`.
Usuń stare klucze `pillars` jeśli istnieją.

```php
'modes' => [
    'swing' => [
        'label'              => 'Swing (1–4 mies.)',
        'valuation_weight'   => 0.40,
        'momentum_weight'    => 0.45,
        'quality_weight'     => 0.15,
        'roc_weights'        => ['1m' => 0.50, '3m' => 0.30, '6m' => 0.20],
        'sigmoid_k'          => 3.0,
        'momentum_cap_min'   => 5.0,
        'momentum_cap_max'   => 95.0,
        'momentum_divisor'   => 40.0,
    ],
    'fundamental' => [
        'label'              => 'Fundamentalny (6–12 mies.)',
        'valuation_weight'   => 0.65,
        'momentum_weight'    => 0.15,
        'quality_weight'     => 0.20,
        'roc_weights'        => ['3m' => 0.30, '6m' => 0.40, '12m' => 0.30],
        'sigmoid_k'          => 3.0,
        'momentum_cap_min'   => 5.0,
        'momentum_cap_max'   => 95.0,
        'momentum_divisor'   => 40.0,
    ],
],
'thresholds' => [
    'strong_buy' => 72,
    'accumulate' => 58,
    'neutral'    => 42,
    'reduce'     => 28,
],
```

- [ ] Dodaj sekcję `modes` do `config/cvs-weights.php`
- [ ] Zachowaj `benchmarks` i `data_source` bez zmian
- [ ] Usuń przestarzałe klucze `pillars` (jeśli istnieją)

---

## Krok 2 — src/CVS/Pillars/QualityPillar.php (NOWY)

Zastępuje `FundamentalQualityPillar.php`. Implementuje Python Filar 3.

**Logika:**
```
pts_gm:
  gm_delta = gross_margins * 100 - bm['median_gm']
  >= 15 → 4 | >= 5 → 3 | >= -5 → 2 | >= -15 → 1 | else → 0

pts_leverage:
  if ebitda > 0:
    ratio = max(0, total_debt - cash) / ebitda
    <= 1 → 3 | <= 2.5 → 2 | <= 4 → 1 | else → 0
  else:
    cr = cash / revenue
    >= 0.30 → 2 | >= 0.10 → 1 | else → 0

pts_growth:
  forward_growth = extractForwardGrowth($financials)
  > 10 → 3 | > 0 → 1.5 | else → 0

score = (pts_gm + pts_leverage + pts_growth) / 10.0 * 100
```

**Konstruktor:** `__construct(array $benchmark, array $config = [])`
**Metoda:** `score(array $financials): float`

- [ ] Utwórz `src/CVS/Pillars/QualityPillar.php`
- [ ] Implementuj logikę Python Filar 3
- [ ] Wyeksponuj `rawScore(): float` i `steps(): array`
- [ ] Napisz `tests/CVS/QualityPillarTest.php` (min. 5 przypadków)

---

## Krok 3 — src/CVS/Pillars/MomentumPillar.php

Dodaj 1M i 12M ROC. Przyjmij `roc_weights` jako parametr `score()`.

```php
$m1  = $closes[max(0, $n - 2)];   // ~1M ago
$m12 = $closes[max(0, $n - 13)];  // ~12M ago
$roc1m  = ($m1  > 0) ? ($now / $m1  - 1.0) * 100.0 : 0.0;
$roc12m = (count($closes) >= 13 && $m12 > 0)
          ? ($now / $m12 - 1.0) * 100.0 : $roc6m;

$composite = 0.0;
if (isset($rocWeights['1m']))  $composite += $rocWeights['1m']  * $roc1m;
if (isset($rocWeights['3m']))  $composite += $rocWeights['3m']  * $roc3m;
if (isset($rocWeights['6m']))  $composite += $rocWeights['6m']  * $roc6m;
if (isset($rocWeights['12m'])) $composite += $rocWeights['12m'] * $roc12m;
```

- [ ] Zaktualizuj `score()` — dodaj `array $rocWeights` jako drugi parametr
- [ ] Dodaj obliczenie ROC_1M i ROC_12M
- [ ] Fallback: brak 12M danych → użyj ROC_6M z wagą 12M
- [ ] Zaktualizuj testy

---

## Krok 4 — src/Api/FinancialDataFetcher.php — OpCF fallback

```php
$fcf  = $v($fin['freeCashflow']      ?? []);
$opCf = $v($fin['operatingCashflow'] ?? []);

$fcfAdjusted = false;
$fcfEffective = $fcf;

if ($fcf !== null && $opCf !== null && $opCf > 0) {
    $capexRatio = ($opCf - $fcf) / $opCf;
    if ($capexRatio > 0.70) {
        $fcfEffective = $opCf * 0.50;
        $fcfAdjusted  = true;
    }
}

// W return:
'free_cash_flow'          => $fcfEffective,
'free_cash_flow_raw'      => $fcf,
'free_cash_flow_adjusted' => $fcfAdjusted,
'operating_cash_flow'     => $opCf,
```

- [ ] Dodaj ekstrakcję `operatingCashflow` z Yahoo Finance
- [ ] Zaimplementuj capex-ratio detection (próg 0.70)
- [ ] Dodaj klucze `free_cash_flow_adjusted`, `free_cash_flow_raw`, `operating_cash_flow`

---

## Krok 5 — src/CVS/CVSModel.php

- [ ] Usuń GrowthPillar i FundamentalQualityPillar
- [ ] Dodaj QualityPillar
- [ ] Wylicz `$momSwing` i `$momFund` osobno (różne roc_weights)
- [ ] Wylicz `$swingCvs` i `$fundCvs` z odpowiednimi wagami
- [ ] Przekaż dual scores do CVSResult
- [ ] Zachowaj Quality Gate (binary pass/fail przed CVS)
- [ ] Zaktualizuj `CVSModelTest.php`:
  - baseFinancials() — dodaj `operating_cash_flow`
  - asercje na `swing_cvs`, `fundamental_cvs`, `pillar_scores.valuation`
  - usuń asercje na `pillar_scores.growth`

---

## Krok 6 — src/CVS/CVSResult.php

```php
private float $swingCvs;
private float $fundamentalCvs;
private string $swingRecommendation;
private string $fundamentalRecommendation;
private ?string $goldenSignal; // 'strong' | 'watchlist' | 'momentum' | null

private function computeGoldenSignal(float $swing, float $fund): ?string
{
    $thr = $this->config['thresholds']['accumulate'] ?? 58;
    if ($swing >= $thr && $fund >= $thr) return 'strong';
    if ($fund  >= $thr && $swing < $thr) return 'watchlist';
    if ($swing >= $thr && $fund  < $thr) return 'momentum';
    return null;
}
```

`toArray()` nowa struktura:
```php
[
    'ticker'       => $this->ticker,
    'quality_gate' => true,
    'swing'        => ['cvs' => ..., 'recommendation' => ...],
    'fundamental'  => ['cvs' => ..., 'recommendation' => ...],
    'golden_signal' => $this->goldenSignal,
    'pillar_scores' => [...],  // valuation, momentum_swing, momentum_fund, quality
    'disclaimer'   => '...',
]
```

- [ ] Nowe pola i gettery
- [ ] `computeGoldenSignal()`
- [ ] `toArray()` nowa struktura
- [ ] Getter `cvs()` zwraca swingCvs (backward compat)

---

## Krok 7 — src/CVS/AnalysisController.php

- [ ] Weryfikacja: JSON response zawiera `swing.cvs`, `fundamental.cvs`, `golden_signal`
- [ ] Weryfikacja: `show()` przekazuje `$financials` do widoku (S-03)

---

## Krok 8 — public/js/app.js

```javascript
// goldenSignal helper
function goldenSignal(r) {
    const map = {
        strong:    { stars: '⭐⭐', label: 'Silny sygnał' },
        watchlist: { stars: '⭐',   label: 'Setup — czekaj na momentum' },
        momentum:  { stars: null,   label: 'Momentum — nie value' },
    };
    return r.golden_signal ? map[r.golden_signal] : null;
}
```

- [ ] Zaktualizuj `buildCard()` — dual score badges (Swing + Fund)
- [ ] Dodaj `goldenSignal()` helper i render
- [ ] Zaktualizuj `initMiniRadars()` — dwa datasety na radarze
- [ ] Zaktualizuj `cardClass()` — bazuje na `r.swing.cvs`

---

## Krok 9 — public/css/app.css

- [ ] Style `.result-card__scores`, `.score-badge`, `.score-badge--swing`, `.score-badge--fund`
- [ ] Style `.result-card__signal` (strong/watchlist/momentum)
- [ ] `.result-card__header` flex
- [ ] Weryfikacja mobile ≥375px

---

## Krok 10 — templates/analysis.php

- [ ] Dual CVS display (dwa kafelki Swing + Fund)
- [ ] Radar chart — dwa datasety (niebieski Swing, złoty Fund)
- [ ] Tabela pilarów — dwie kolumny wag (Waga Swing / Waga Fund)
- [ ] Wiersz `FCF adjusted` w raw data panel

---

## Krok 11 — usuwanie starych plików

- [ ] Usuń `src/CVS/Pillars/GrowthPillar.php`
- [ ] Usuń `src/CVS/Pillars/FundamentalQualityPillar.php`
- [ ] Rename/delete `src/CVS/Pillars/SectorBenchmarkPillar.php` → ValuationPillar.php

---

## Acceptance criteria

1. Każda karta pokazuje Swing CVS i Fund CVS jednocześnie
2. MELI → ⭐ "Setup — czekaj na momentum" (Fund≥58, Swing<58)
3. Spółka z obu ≥58 → ⭐⭐ "Silny sygnał"
4. XOM valuation > 0 po OpCF fix
5. Radar na detail page: dwie linie (Swing niebieski, Fund złoty)
6. `vendor/bin/phpunit` → 0 failures
7. CLAUDE.md — zaktualizuj architekturę (3 pilary, dual mode)
