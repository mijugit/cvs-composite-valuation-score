# Frame Brief: Faza 3 — doskonalenie oceny CVS

> Krok framingu przed /10x-plan. Oddziela to, co *faktycznie* jest problemem,
> od tego, co przyjęto na wejściu jako rozwiązanie.

## Reported Observation

Ocena CVS jest niedoskonała. Cztery konkretne miejsca w mechanice modelu
wyglądają na metodologicznie słabe:

1. **Mediany sektorowe = 12 zahardkodowanych stałych** z Pythona v1.6
   (`config/cvs-weights.php:50-63`), nie liczone empirycznie z populacji
   ~600 spółek ani odświeżane.
2. **Granularność sektora zbyt gruba** — np. „Communication Services" miesza
   NFLX (streaming) i TTWO (gry/GTA). Nie porównujemy jabłek z jabłkami.
3. **Sigmoid k=3 w Valuation zbyt stromy** — ratio 2× od mediany → ~5 pkt,
   2× taniej → ~95; karze/nagradza prawie binarnie (`ValuationPillar.php:166-170`).
4. **Filar Quality skokowy** — punkty progowe (0/1/2/3/4) → spółka tuż pod
   progiem traci cały punkt, wrażliwość na szum z Yahoo (`QualityPillar.php:66-123`).

## Initial Framing (preserved)

- **User's stated cause or approach**: 4 niezależne defekty mechaniki do naprawy
  punkt po punkcie.
- **User's proposed direction**: mediany empiryczne z `cvs_snapshots`; sub-sektory /
  peer groups; łagodniejszy sigmoid; wygładzony Quality.
- **Pre-dispatch narrowing**: sygnał = **intuicja ekspercka** + konkretna obserwacja
  granularności (TTWO vs NFLX w jednym worku); struktura = **4 oddzielne**;
  sukces = **sensowność wyników** (osąd ekspercki, NIE backtest/hit-rate).

## Dimension Map

Obserwacja „CVS ocenia niedoskonale" mogłaby wynikać z:

1. **Źródło i świeżość benchmarku** — wartości median (skąd, jak aktualne). ← framing P1
2. **Granularność jednostki odniesienia** — do czego porównujemy (sektor vs industry vs peer). ← framing P2
3. **Kształt funkcji transformującej** — sigmoid / progi (jak ratio→score). ← framing P3, P4
4. **Jakość danych wejściowych** — szum Yahoo zanim dotrze do transformaty. ← częściowo P4
5. **Brak sygnału prawdy (ground truth)** — czym w ogóle mierzymy „lepiej" (meta-wymiar).

## Hypothesis Investigation

| Hypothesis | Evidence | Verdict |
| --- | --- | --- |
| **D1 Źródło median** to słaby punkt | 12 stałych wpisanych ręcznie, `DEFAULT` fallback, brak związku z realną populacją (`cvs-weights.php:50-63`). Realne, ale tylko 12 liczb | WEAK (objaw, nie korzeń) |
| **D2 Granularność** to korzeń | Filar liczy ratio vs JEDNA mediana na sektor (`ValuationPillar.php:40`, `CVSModel.php:71-72`). Sektor Yahoo to 11 ogromnych worków. `industry` (drobny) JEST pobierany ale **nieużywany w scoringu** (`FinancialDataFetcher.php:414`) | **STRONG** |
| **D3 Kształt transformaty** psuje wynik | k=3 sztywny, choć z configu (`ValuationPillar.php:168`); Quality progowy skokowy (`QualityPillar.php:69-73`). Realne, ale wtórne wobec tego, *co* podajemy na wejście | WEAK |
| **D4 Szum danych** | Guardy już istnieją (gross=0 artefakt, capex/FCF fallback, currency guard) — `FinancialDataFetcher.php:373-402` | WEAK |
| **D5 Brak ground truth** | TrackRecord istnieje ale snapshoty od ~2026-06-01 → pierwsze hit/miss ~lipiec 2026 (`TrackRecordCalculator.php`). Dziś brak sygnału | NONE (świadomie odłożone przez użytkownika) |

## Narrowing Signals

- Użytkownik **wprost odrzucił** reframe „brak ground truth" (D5): sukces = sensowność
  wyników / osąd ekspercki, nie pomiar ex-post. → D5 poza zakresem fazy 3 (ale patrz ryzyko).
- Użytkownik **sam wskazał D2** jako najżywszy ból (TTWO vs NFLX) i nazwał blokadę:
  „skąd wziąć dane na drzewo sektorów i przypisać spółki do podsektorów".
- Odkrycie w kodzie: blokada D2 jest **w 80% rozwiązana** — `industry` z Yahoo daje
  darmowe 2-poziomowe drzewo (sektor→industry), już w payloadzie, zero extra API.

## Cross-System Convention

Standard branżowy (relative valuation): porównuje się spółkę do **peer group /
sub-industry**, nie do całego sektora GICS-poziom-1. Yahoo `industry` ≈ GICS
sub-industry/industry. Konwencja potwierdza D2: gruba jednostka odniesienia to
realna wada metodologiczna, nie kosmetyka.

## Reframed (or Confirmed) Problem Statement

> **Problem do zaplanowania**: jednostka odniesienia jest zbyt gruba (sektor zamiast
> peer-group), a punkty P1 (źródło median) i P2 (granularność) NIE są niezależne —
> to jedna decyzja: *„do jakiej populacji i jak licznej porównujemy spółkę".*

Framing użytkownika (4 oddzielne, sukces = sensowność) **w większości się obronił** —
P3 i P4 zostają jako samodzielne, wtórne usprawnienia kształtu transformat. Ale jedna
korekta jest konieczna: **P1 i P2 są sprzężone**. Mediana empiryczna (P1) jest lepsza
od 12 stałych tylko wtedy, gdy w worku jest dość spółek; im głębiej schodzisz w drzewo
(P2), tym chudsze worki → tym bardziej niestabilna mediana empiryczna. Nie da się
zdecydować metody P1 bez ustalenia głębokości drzewa P2. To granularność rządzi
medianą, nie odwrotnie.

## Confidence

**MEDIUM** — silny dowód w kodzie i zgodność z konwencją na D2 (granularność jako korzeń
P1+P2). MEDIUM, nie HIGH, bo dwie rzeczy do rozstrzygnięcia w planowaniu, nie we framingu:
(a) ile spółek/bucket to próg wiarygodnej mediany empirycznej przy ~600 nazwach; (b) czy
brać ~150 surowych `industry` Yahoo, czy zwinąć je w kurowaną warstwę pośrednią.

## What Changes for /10x-plan

Plan fazy 3 powinien potraktować **P1+P2 jako jeden slice** („peer-group/sub-sektorowa
jednostka odniesienia + mediany liczone na właściwym poziomie drzewa, z progiem
populacji i fallbackiem w górę drzewa gdy bucket za chudy"), a **P3 (sigmoid) i P4
(Quality)** jako dwa osobne, mniejsze slice'y wygładzenia transformat. Sukces każdego
slice'a mierzony osądem eksperckim na zestawie znanych spółek — z świadomym ryzykiem
overfittingu do mega-capów (ground-truth z TrackRecord dojrzeje ~lipiec 2026 i może
posłużyć później jako walidacja ex-post — fazy 3 nie należy projektować tak, by to
wykluczyć).

## References

- Source files: `config/cvs-weights.php:50-63`, `src/CVS/Pillars/ValuationPillar.php:40,166-170`,
  `src/CVS/Pillars/QualityPillar.php:66-123`, `src/CVS/CVSModel.php:71-82`,
  `src/Api/FinancialDataFetcher.php:414` (industry pobierany), `src/TrackRecord/TrackRecordCalculator.php`
- Investigation: dowód znaleziony bezpośrednim odczytem kodu (bez parallel sub-agentów — guardrail #6/#7)
