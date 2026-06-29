# Wirtualny Portfel — autonomiczny zarządca CVS + LLM

Globalny portfel papierowy ($10 000 startowo), zarządzany autonomicznie przez model
CVS w połączeniu z LLM (Claude). Horyzont **swing (1–4 miesiące)**. Decyzje są
systematyczne i deterministyczne tam, gdzie to możliwe; LLM odpowiada wyłącznie za
selekcję i ranking w ramach twardych reguł egzekwowanych po stronie serwera.

🔗 Live: https://cvs.timeflow.fun/portfolio

> ⚠️ Portfel jest hipotezą modelu analitycznego, nie rekomendacją inwestycyjną.

---

## 1. Strategia — reguły działania

Wszystkie progi pochodzą z `config/portfolio.php → strategy` (FR-010: nigdy
zahardkodowane). Zmiana wartości w configu automatycznie aktualizuje prompt LLM,
egzekwowanie po stronie serwera oraz sekcję „Na jakich zasadach działa portfel"
na stronie portfela.

### 🟢 Reguły zakupu (BUY)
1. Kupujemy **wyłącznie** spółki z golden signal `strong` (CVS Swing ≥ 58 **oraz** Fund ≥ 58).
2. Twardy limit **sektorowy**: maks. `max_sector_pct` (40%) wartości portfela na sektor.
3. Twardy limit **na spółkę**: maks. `max_weight_pct` (15%); waga docelowa ~`target_weight_pct` (10%).
4. Min. `min_emerging_positions` (2) pozycje z pasma **emerging** (Swing 58–72) — wczesne wejścia, pretendenci do SILNE KUPUJ.
5. Cel ~`target_positions` (10) pozycji; brak odkupu w tym samym cyklu spółki właśnie sprzedanej.
6. `quantity = floor(target_weight_usd / cena)`; jeśli wynik 0 ale 1 akcja ≤ limitu spółki → kup 1; inaczej pomiń (nigdy BUY z quantity 0).

### 🔵 Reguły utrzymania (HOLD)
1. Trzymamy, dopóki strata nie sięgnie −`stop_loss_pct` (15%) i zysk nie sięgnie +`take_profit_pct` (25%).
2. CVS Swing ≥ `sell_swing_below` (54) i sygnał nadal strong/watchlist.
3. Waga pozycji w limicie (≤ 15%).
4. **Histereza** 58/54: wchodzimy przy Swing ≥ 58, wychodzimy dopiero < 54 — ogranicza nadmierny obrót.

### 🔴 Reguły sprzedaży (SELL) — kolejność priorytetu
1. **Stop-loss** — P&L ≤ −15% → sprzedaż całości. *Twarda* ochrona kapitału, wymuszana przez serwer.
2. **Take-profit** — P&L ≥ +25% → realizacja. *Miękka* (decyzja LLM); wyjątek dla wciąż przyspieszającego stronga (Swing ≥ 72).
3. **Załamanie sygnału** — Swing < 54, lub reko REDUKUJ/UNIKAJ, lub utrata sygnału strong (golden → momentum/null).
4. **Przekroczenie wagi** — pozycja > 15% portfela → przycięcie do wagi docelowej.

---

## 2. Architektura potoku decyzyjnego

```
cron (okno sesji NYSE)
  └─ bin/portfolio-rebalance.php
       1. MarketCalendar         → brama: dzień handlowy + okno rebalansu
       2. CycleRepository        → claimForRun(): brama idempotencji + retry
       3. ScreenerRepository     → świeże sygnały CVS (po dziennym rescore)
       4. DecisionService        → buduje prompt (metodologia+strategia+dane), woła LLM
       5. DecisionParser         → walidacja JSON, odporny na pojedyncze złe pozycje
       6. (wstrzyknięcie cen)     → realne price_at_snapshot per ticker
       7. DecisionEnforcer       → TWARDE limity: stop-loss, sektor, spółka, anti-rebuy
       8. PortfolioService       → executeCycle(): atomowa transakcja DB
```

### Komponenty (`src/Portfolio/`)
| Klasa | Rola |
|---|---|
| `DecisionService` | Buduje system prompt (metodologia CVS + reguły strategii z configu) i blok danych (pre-filtr do `strong` + pozycje, P&L, wagi). Woła LLM, parsuje, zapisuje audyt. **Nigdy nie rzuca** — zwraca typowany wynik. |
| `DecisionParser` | Waliduje JSON odpowiedzi. Zdejmuje markdown fences. Pojedyncza zła pozycja (np. BUY qty 0) jest pomijana, nie kasuje batcha; tylko strukturalnie zepsuta odpowiedź → parse_error (retry). |
| `DecisionEnforcer` | **Autorytatywny guard.** Przycina BUY do limitów sektor∩spółka∩gotówka; wymusza stop-loss (force-SELL stratnych pozycji nawet gdy LLM dał HOLD); blokuje odkup w tym samym cyklu. |
| `PortfolioService` | `executeCycle()` — atomowa transakcja (BUY/SELL/HOLD/SKIP), upsert pozycji, ledger transakcji, podsumowanie cyklu. Rollback przy każdym wyjątku. |
| `CycleRepository` | Brama cyklu: `claimForRun()` (idempotencja + ograniczone retry), audyt LLM, podsumowanie finansowe. |
| `MarketCalendar` | NYSE: dzień handlowy + okno rebalansu. Czas wstrzykiwany (deterministyczny, testowalny). |
| `PortfolioRepository` | Read-only: stan, pozycje z ceną, historia cykli, uzasadnienia per ticker. |
| `LivePriceProvider` | Live kursy posiadanych spółek (przez `LatestPriceSource`), z fallbackiem do snapshotu. |

### Dlaczego twarde guardy po stronie serwera
LLM potrafi *opisać* respektowanie limitu („redukuję CSCO do 5"), ale wpisać
nieprzyciętą wartość w pole `quantity`. Dlatego ochrona kapitału (stop-loss) i
limity koncentracji (sektor/spółka) są **wymuszane deterministycznie** w
`DecisionEnforcer`, niezależnie od tego, co zwrócił model. Take-profit pozostaje
miękki (osąd modelu), bo korzysta z kontekstu „czy spółka wciąż przyspiesza".

---

## 3. Runbook operacyjny

### Okno rebalansu
Pełna sesja NYSE: **09:30–16:00 ET = 15:30–22:00 Warsaw** (`rebalance_window_minutes: 390`).
Skrypt wykona się tylko wewnątrz tego okna i w dzień handlowy; *kiedy dokładnie* — steruje cron.

### Cron (panel CF, typ „Ścieżka", PHP 8.2)
Retry dziennego cyklu jest ograniczony do `max_daily_attempts` (3) i odpala się **tylko
gdy poprzednia próba tego dnia padła** (`llm_failed`/`failed`). Timing retry steruje cron —
zalecane wpisy z narastającym odstępem 5/10/15 min:

```
35 21 * * 1-5   /usr/local/bin/php82 /home/.../bin/portfolio-rebalance.php   # próba 1 (21:35)
40 21 * * 1-5   /usr/local/bin/php82 /home/.../bin/portfolio-rebalance.php   # retry +5
50 21 * * 1-5   /usr/local/bin/php82 /home/.../bin/portfolio-rebalance.php   # retry +10
05 22 * * 1-5   /usr/local/bin/php82 /home/.../bin/portfolio-rebalance.php   # retry +15
```

Logika bramy (`CycleRepository::claimForRun`):
- brak wiersza dnia → nowy cykl (attempt 1)
- `completed` → pomiń (portfel nietknięty)
- `started` → pomiń (ochrona przed równoległym wykonaniem)
- `llm_failed`/`failed` + attempt < max → retry na tym samym wierszu (`attempt_count++`)
- attempt ≥ max → stop do następnego dnia

### Logi
`logs/portfolio-rebalance.log` (własny log skryptu — *tu* jest treść). Log panelu CF
(`~/portfolio-rebalance.log`) bywa pusty, bo skrypt pisze do własnego pliku, nie na stdout.

### Migracje
Addytywne, numerowane SQL w `database/migrations/`. Wymagana dla retry:
`028_add_attempt_count_to_rebalance_cycle.sql`.

---

## 4. Ceny live na stronie portfela

Przy wejściu/odświeżeniu `/portfolio` odświeżamy kursy posiadanych spółek i
przeliczamy portfel:
- **Cache sesyjny 15 min** — nie pytamy Yahoo częściej.
- **Timeout 5 s/ticker** — strona się nie zawiesi przy wolnym API.
- **Fallback** — API padło → ostatnia cena z bazy (snapshot CVS, już w USD); badge `wycena` zamiast `live`.
- **Tickery nie-USD** (z sufiksem, np. `.WA`) zostają na snapshocie USD (brak ryzyka walutowego).
- Kolumna **Wynik** (P&L%) per pozycja oraz żywe „WYNIK VS START".

---

## 5. Referencja konfiguracji (`config/portfolio.php → strategy`)

| Klucz | Domyślnie | Znaczenie |
|---|---:|---|
| `target_positions` | 10 | docelowa liczba pozycji (widełki 8–12) |
| `target_weight_pct` | 10 | docelowa waga pozycji (% portfela) |
| `max_weight_pct` | 15 | **twardy** limit na spółkę |
| `max_sector_pct` | 40 | **twardy** limit na sektor |
| `min_emerging_positions` | 2 | min. pozycji z pasma emerging |
| `buy_signal` | `strong` | golden signal wymagany do BUY |
| `emerging_swing_low` / `_high` | 58 / 72 | pasmo „emerging" (Swing) |
| `sell_swing_below` | 54 | próg wyjścia (histereza z 58) |
| `take_profit_pct` | 25 | realizacja zysku (**miękka**, LLM) |
| `stop_loss_pct` | 15 | stop-loss (**twardy**, serwer) |
| `max_daily_attempts` | 3 | ograniczone retry dziennego cyklu |

Pozostałe sekcje: `market` (godziny NYSE), `rebalance_window_minutes`, `llm`
(timeout 45 s, max_tokens), `holidays` (kalendarz NYSE — aktualizować corocznie).

---

## 6. Changelog — sesja utwardzania strategii (2026-06-28/29)

Kolejność, w jakiej system dojrzewał od „działa, ale szablonowo" do produkcyjnego:

1. **Strefa czasowa „następnego dnia rebalansu"** — `today` w Europe/Warsaw mapował się na poprzedni wieczór ET; przejście na `now` w `America/New_York`.
2. **Zegar rynku** na stronie portfela (Warszawa/NY na żywo, godziny sesji, status otwarty/zamknięty).
3. **Okno rebalansu** otwarte na pełną sesję (30 min → 390 min); czas steruje cron.
4. **Parser** — zdejmowanie markdown fences; `quantity 0` przy HOLD normalizowane do null.
5. **Mapowanie ceny** — `price_at_snapshot` zamiast nieistniejącego klucza `price` (LLM widział `$-` i kupował po 1 szt.).
6. **Strategia CVS** — pusty szablon zastąpiony pełną metodologią + regułami; pre-filtr screenera do `strong` + pozycje; wagi i P&L w prompcie.
7. **DecisionEnforcer** — twarde limity sektor/spółka (LLM przebijał 40% tech).
8. **Deadlock egzekucji** (SQLSTATE 1205) — `executeCycle` i `cycleRepo` na jednej connection, świeżej, po wywołaniu LLM.
9. **Zakupy po $0** — wstrzyknięcie realnych cen przed egzekucją (LLM nie zwraca `price_usd`).
10. **Ograniczone retry** — `claimForRun` + `attempt_count` (migracja 028); timeout LLM 20 s → 45 s.
11. **Wyjścia P&L** — twardy stop-loss (−15%), miękki take-profit (+25%), histereza 50 → 54, guard anti-rebuy.
12. **UX portfela** — ceny live (cache 15 min, fallback), ⓘ z uzasadnieniem per pozycja, sekcja reguł działania.

### Lekcja przewodnia
Reguły arytmetyczne wymagające „sumy kroczącej" (limit sektorowy, stop-loss) **muszą**
być wymuszane po stronie serwera — LLM potrafi je opisać poprawnie w uzasadnieniu, ale
nie utrzymuje ich w polach strukturalnych. Patrz `DecisionEnforcer`.
