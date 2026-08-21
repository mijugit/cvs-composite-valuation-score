<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\Api\FundamentalFieldRegistry;
use DateTimeImmutable;

/**
 * Builds the validation prompt, calls GeminiClient with web-search grounding,
 * and parses the JSON response into a diff — change: fundamentals-validation.
 *
 * Copies LlmGeminiContextGatherer's exact call shape (googleSearch tool,
 * GeminiClientFactory::fromConfig()) rather than inventing a new one — see
 * src/LlmGemini/LlmGeminiContextGatherer.php. Prompt wording is the one
 * manually validated across three independent LLM providers before this
 * feature was built (context/changes/fundamentals-validation/research.md).
 *
 * Earnings-timing fields are asked as DATES, never day-counts — GeminiClient
 * has no "today" of its own to reason from, and asking for a day-count would
 * make the model silently assume one. FundamentalFieldRegistry::EARNINGS_DATE_FIELDS
 * maps the date field asked for to the day-count field actually stored; this
 * class converts the two locally, once, against the current date at parse time.
 */
class FundamentalsValidationService
{
    private const MAX_TOKENS = 2048;

    /** @param array<string, mixed> $geminiConfig config/gemini.php contents */
    public function __construct(
        private readonly array $geminiConfig,
    ) {
    }

    /**
     * @param  list<string>          $fieldsToCheck   FinancialDataFetcher field names (day-count
     *                                                 names, not date names — translated internally)
     * @param  array<string, mixed>  $currentValues   $financials, for the "for comparison" block
     */
    public function validate(
        string $ticker,
        string $sector,
        array $fieldsToCheck,
        array $currentValues,
        ?GeminiClient $clientOverride = null,
    ): FundamentalsValidationResult {
        $client = $clientOverride ?? GeminiClientFactory::fromConfig($this->geminiConfig);

        $promptKeys = $this->promptKeysFor($fieldsToCheck);

        $result = $client->sendMessage(
            [['role' => 'user', 'content' => $this->buildUserMessage($ticker, $sector, $promptKeys, $fieldsToCheck, $currentValues)]],
            $this->buildSystemPrompt(),
            [
                'max_tokens' => self::MAX_TOKENS,
                'tools'      => [['googleSearch' => new \stdClass()]],
            ]
        );

        if (!$result->ok || $result->text === null || trim($result->text) === '') {
            return FundamentalsValidationResult::failure(
                $result->failureMessage ?? 'Brak odpowiedzi modelu.'
            );
        }

        return $this->parseResponse($result->text, $fieldsToCheck, $currentValues, (string) ($result->model ?? ''));
    }

    // -----------------------------------------------------------------------
    // Prompt
    // -----------------------------------------------------------------------

    /**
     * Maps each requested day-count field to the key actually asked for in
     * the prompt/JSON (date fields for earnings timing, the field itself
     * otherwise).
     *
     * @param  list<string>            $fieldsToCheck
     * @return array<string, string>   requested field name => prompt/JSON key
     */
    private function promptKeysFor(array $fieldsToCheck): array
    {
        $dateFieldByDayCount = array_flip(FundamentalFieldRegistry::EARNINGS_DATE_FIELDS);

        $out = [];
        foreach ($fieldsToCheck as $field) {
            $out[$field] = $dateFieldByDayCount[$field] ?? $field;
        }
        return $out;
    }

    /**
     * @param array<string, string> $promptKeys
     * @param list<string>          $fieldsToCheck
     * @param array<string, mixed>  $currentValues
     */
    private function buildUserMessage(string $ticker, string $sector, array $promptKeys, array $fieldsToCheck, array $currentValues): string
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $schemaLines  = [];
        $currentLines = [];
        foreach ($fieldsToCheck as $field) {
            $promptKey      = $promptKeys[$field];
            $schemaLines[]  = '  "' . $promptKey . '": liczba lub null (lub data YYYY-MM-DD, jeśli pole dotyczy daty)';
            $current        = $currentValues[$field] ?? null;
            $currentLines[] = '- ' . $field . ': ' . ($current === null ? 'brak danych' : (string) $current);
        }
        $schema  = implode(",\n", $schemaLines);
        $current = implode("\n", $currentLines);

        return <<<MSG
DZISIEJSZA DATA: {$today}

Potrzebuję aktualnych danych fundamentalnych dla spółki {$ticker} (sektor: {$sector}).

WAŻNE: wyszukaj te dane na żywo (web search), nie odpowiadaj z pamięci/danych treningowych.
Jeśli nie jesteś pewien lub nie znajdujesz wiarygodnego źródła — użyj wartości null, nie
zgaduj i nie ekstrapoluj.

Potrzebuję odpowiedzi WYŁĄCZNIE w formacie JSON z dokładnie tymi polami:
{
{$schema},
  "notes": "wszelkie niespójności które zauważyłeś, lub informacja że jakieś pole nie istnieje w dostępnych źródłach"
}

Dla porównania — to są wartości, które mamy obecnie (część może być błędna lub
przeterminowana, o to właśnie pytam):
{$current}

Jeśli Twoje dane różnią się istotnie od powyższych, wyraźnie to zaznacz w polu "notes".
MSG;
    }

    private function buildSystemPrompt(): CacheableSystem
    {
        $text = <<<'SYSTEM'
You are a data-verification assistant for a quantitative stock-scoring model.
Search the live web for the requested fundamental data points and return
ONLY the requested JSON object — no prose outside the JSON. Never guess or
extrapolate a value; use null when you cannot find a reliable source. Write
the "notes" field in Polish.
SYSTEM;

        return new CacheableSystem($text, CacheableSystem::TTL_5M);
    }

    // -----------------------------------------------------------------------
    // Response parsing
    // -----------------------------------------------------------------------

    /**
     * @param list<string>         $fieldsToCheck
     * @param array<string, mixed> $currentValues
     */
    private function parseResponse(string $text, array $fieldsToCheck, array $currentValues, string $model): FundamentalsValidationResult
    {
        $decoded = $this->decodeJson($text);
        if ($decoded === null) {
            return FundamentalsValidationResult::failure('Nieprawidłowa odpowiedź modelu (nie JSON).');
        }

        $dateFieldByDayCount = array_flip(FundamentalFieldRegistry::EARNINGS_DATE_FIELDS);
        $today               = new DateTimeImmutable();

        $diff = [];
        foreach ($fieldsToCheck as $field) {
            $dateField = $dateFieldByDayCount[$field] ?? null;

            if ($dateField !== null) {
                $raw = $decoded[$dateField] ?? null;
                $new = is_string($raw) ? $this->dayCountFromDate($raw, $field, $today) : null;
            } else {
                $raw = $decoded[$field] ?? null;
                $new = is_numeric($raw) ? (float) $raw : null;
            }

            $diff[$field] = [
                'old'    => $currentValues[$field] ?? null,
                'new'    => $new,
                'status' => $new !== null ? 'validated' : 'checked_no_data',
            ];
        }

        $notes = is_string($decoded['notes'] ?? null) ? $decoded['notes'] : '';

        return FundamentalsValidationResult::success($diff, $notes, $model);
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(string $text): ?array
    {
        // Models sometimes wrap JSON in a ```json fenced block despite being
        // asked not to — strip fences before decoding rather than failing.
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $trimmed) ?? $trimmed;

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function dayCountFromDate(string $dateStr, string $dayCountField, DateTimeImmutable $today): ?float
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if ($date === false) {
            return null;
        }

        // days_since_earnings: today - date (positive when date is in the past).
        // days_to_earnings:    date - today (positive when date is upcoming;
        //                      may be negative, mirroring FinancialDataFetcher's
        //                      own EarningsTiming semantics).
        $deltaDays = (int) floor(($date->getTimestamp() - $today->getTimestamp()) / 86400);

        return $dayCountField === 'days_since_earnings' ? (float) (-$deltaDays) : (float) $deltaDays;
    }
}
