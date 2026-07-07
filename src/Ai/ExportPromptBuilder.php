<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Assembles the export prompt that users paste into their own LLM.
 *
 * The prompt is intentionally one-directional: no callback URL, no JSON
 * instruction, no Base64 encoding. It contains:
 *   - an anchor rule (CVS numbers must not be changed)
 *   - the CVS data block (built by AiDivergenceService::buildDataBlock)
 *   - our AI narrative (from the shared cache)
 *   - four tasks for the external model (catalysts / gap / critique / scenarios)
 *   - the mandatory disclaimer
 *
 * Pure function — no I/O, no randomness, no date() calls.
 */
final class ExportPromptBuilder
{
    /**
     * @param string $ticker      Stock symbol, e.g. "AAPL"
     * @param string $sector      Sector label, e.g. "Technology"
     * @param string $dataBlock   Output of AiDivergenceService::buildDataBlock()
     * @param string $aiAnalysis  Cached AI narrative text (content from ai_analyses table)
     * @param string $lang        Language: 'pl' or 'en' (unknown value falls back to 'pl')
     */
    public function build(
        string $ticker,
        string $sector,
        string $dataBlock,
        string $aiAnalysis,
        string $lang = 'pl'
    ): string {
        $lang = in_array($lang, ['pl', 'en'], true) ? $lang : 'pl';

        return $lang === 'en'
            ? $this->buildEn($ticker, $sector, $dataBlock, $aiAnalysis)
            : $this->buildPl($ticker, $sector, $dataBlock, $aiAnalysis);
    }

    // ------------------------------------------------------------------
    // Polish variant
    // ------------------------------------------------------------------

    private function buildPl(
        string $ticker,
        string $sector,
        string $dataBlock,
        string $aiAnalysis
    ): string {
        return <<<PROMPT
Działasz jako doświadczony analityk inwestycyjny i krytyczny recenzent. Otrzymujesz gotową
analizę ilościową spółki {$ticker} z modelu CVS (Composite Valuation Score) wraz z twardymi
danymi, na których ją zbudowano. Twoim zadaniem NIE jest powtórzenie tej analizy, tylko jej
POGŁĘBIENIE o bieżący kontekst rynkowy ORAZ KRYTYCZNA OCENA.

══════════════════════════════════════════════════════
ZASADA NADRZĘDNA (KOTWICA — OBOWIĄZKOWA)
══════════════════════════════════════════════════════
Liczby modelu CVS (score, filary, fair value) są DETERMINISTYCZNE i policzone z danych
finansowych. NIE zmieniaj ich ani nie „przeliczaj" po swojemu. Możesz się z ich
INTERPRETACJĄ nie zgadzać — ale wtedy powiedz to wprost i uzasadnij, zamiast podmieniać wynik.
Cytując liczby z bloku danych, przepisuj je dokładnie — każda rozbieżność z kotwicą to błąd.

══════════════════════════════════════════════════════
TWARDE DANE Z MODELU CVS — {$ticker} (SEKTOR: {$sector})
══════════════════════════════════════════════════════
{$dataBlock}

══════════════════════════════════════════════════════
NASZA ANALIZA AI (do pogłębienia i krytycznej oceny)
══════════════════════════════════════════════════════
{$aiAnalysis}

══════════════════════════════════════════════════════
TWOJE ZADANIA (wykonaj po kolei)
══════════════════════════════════════════════════════
1. ŚWIEŻE KATALIZATORY: Przeszukaj dostępne Ci źródła pod kątem newsów z ostatnich ~14 dni
   o {$ticker} i jej sektorze ({$sector}): nowe produkty, roszady w zarządzie, rekomendacje
   banków inwestycyjnych, ryzyko zdarzeniowe wokół najbliższych wyników finansowych.
   Podaj DATY dla każdego znalezionego faktu.

2. CZEGO MODEL NIE WIE: Połącz te wydarzenia z liczbami CVS. Czy słabe/mocne momentum
   wynika z realnego wydarzenia, czy z rotacji kapitału? Czy rynek płaci premię lub dyskonto
   za coś, czego model liczbowo nie widzi?

3. KRYTYKA NASZEJ ANALIZY: Wskaż konkretnie, gdzie powyższa analiza AI jest Twoim zdaniem
   zbyt optymistyczna, zbyt ostrożna lub pomija istotny czynnik. Bądź bezpośredni — to jest
   cel tego ćwiczenia.

4. DWA SCENARIUSZE: Przedstaw scenariusz optymistyczny i pesymistyczny na najbliższe tygodnie,
   odnosząc się bezpośrednio do strefy ATR i ceny godziwej modelu z danych powyżej.

Odpowiedz zwięźle, punktorami, konkretnie — bez ogólników. Mów jak rasowy inwestor.

⚠️ Powyższa analiza to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
PROMPT;
    }

    // ------------------------------------------------------------------
    // English variant
    // ------------------------------------------------------------------

    private function buildEn(
        string $ticker,
        string $sector,
        string $dataBlock,
        string $aiAnalysis
    ): string {
        return <<<PROMPT
You are an experienced investment analyst and critical reviewer. You are given a complete
quantitative analysis of {$ticker} from the CVS model (Composite Valuation Score) along with
the hard data it was built on. Your task is NOT to repeat this analysis, but to DEEPEN it
with current market context AND provide a CRITICAL REVIEW.

══════════════════════════════════════════════════════
PRIME DIRECTIVE (ANCHOR — MANDATORY)
══════════════════════════════════════════════════════
The CVS model numbers (score, pillars, fair value) are DETERMINISTIC — computed from financial
data. Do NOT change or "recalculate" them on your own. You MAY disagree with their
INTERPRETATION — but then say so explicitly and justify it, rather than substituting the result.
When quoting numbers from the data block, transcribe them exactly — any mismatch with the
anchor is an error.

══════════════════════════════════════════════════════
CVS MODEL HARD DATA — {$ticker} (SECTOR: {$sector})
══════════════════════════════════════════════════════
{$dataBlock}

══════════════════════════════════════════════════════
OUR AI ANALYSIS (to be deepened and critically reviewed)
══════════════════════════════════════════════════════
{$aiAnalysis}

══════════════════════════════════════════════════════
YOUR TASKS (execute in order)
══════════════════════════════════════════════════════
1. FRESH CATALYSTS: Search available sources for news from the last ~14 days about {$ticker}
   and its sector ({$sector}): new products, management changes, investment bank recommendations,
   event risk around upcoming earnings. Provide DATES for every fact you cite.

2. WHAT THE MODEL DOESN'T KNOW: Connect those events to the CVS numbers. Does weak/strong
   momentum stem from a real event or from capital rotation? Is the market pricing in a
   premium or discount for something the model cannot capture numerically?

3. CRITIQUE OF OUR ANALYSIS: Point out specifically where the above AI analysis is, in your
   view, too optimistic, too cautious, or missing an important factor. Be direct — that is
   the purpose of this exercise.

4. TWO SCENARIOS: Present an optimistic and a pessimistic scenario for the coming weeks,
   referencing the ATR accumulation zone and the model's fair value from the data above.

Answer concisely, in bullet points, specifically — no generic statements. Talk like a seasoned investor.

⚠️ This analysis is a hypothesis from an analytical model, not an investment recommendation. Invest responsibly.
PROMPT;
    }
}
