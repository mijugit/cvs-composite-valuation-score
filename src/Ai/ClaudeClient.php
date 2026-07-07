<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Claude Messages API client (Anthropic).
 *
 * Guardrails (phase 2):
 *  - NEVER throws to the caller — every error path returns AiResult::failure(...).
 *  - Retries transient failures (429 / 529 / 5xx / timeout / network) with
 *    exponential backoff, capped by a total wall-clock budget so user-perceived
 *    time stays under the NFR (< 30s).
 *  - Optional prompt caching via a CacheableSystem block (cache_control ephemeral).
 *  - The API key is only ever sent as the x-api-key header — never logged, never
 *    placed on AiResult.
 *
 * Network is behind the HttpTransport seam, so this class is fully unit-testable
 * offline (inject a FakeTransport).
 *
 * Note on retry: backoff is exponential without honoring Retry-After, because the
 * HttpTransport contract intentionally does not expose response headers. The total
 * budget cap keeps this safe within the < 30s NFR.
 */
final class ClaudeClient
{
    /** @param array<string, mixed> $config  config/ai.php contents */
    public function __construct(
        private readonly array $config,
        private readonly HttpTransport $transport,
    ) {}

    /** Hard cap on server-side pause_turn continuations per sendMessage() call. */
    private const MAX_CONTINUATIONS = 2;

    /**
     * Send a Messages API request and return a typed result.
     *
     * @param list<array{role: string, content: mixed}> $messages
     * @param array<string, mixed>                        $options   e.g. ['max_tokens' => 1024, 'tools' => [...]]
     */
    public function sendMessage(array $messages, ?CacheableSystem $system = null, array $options = []): AiResult
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            error_log('[Ai] missing ANTHROPIC_API_KEY');
            return AiResult::failure(AiFailureKind::Auth, 'Brak klucza API — analiza AI niedostępna.');
        }

        $url        = (string) ($this->config['base_url'] ?? 'https://api.anthropic.com/v1/messages');
        $model      = (string) ($this->config['model'] ?? '');
        $version    = (string) ($this->config['anthropic_version'] ?? '2023-06-01');
        $maxTokens  = max(1, (int) ($options['max_tokens'] ?? $this->config['max_tokens'] ?? 2048));
        $timeout    = (int) ($this->config['timeout'] ?? 20);
        $maxRetries = (int) ($this->config['max_retries'] ?? 2);
        $budget     = (float) ($this->config['total_timeout'] ?? 25);
        $baseDelay  = (int) ($this->config['retry_base_delay_ms'] ?? 500);
        /** @var list<array<string, mixed>> $tools */
        $tools      = is_array($options['tools'] ?? null) ? $options['tools'] : [];

        $headers = $this->buildHeaders($apiKey, $version, $system);
        $deadline = microtime(true) + $budget;
        $attempt  = 0;
        $continuations   = 0;
        $workingMessages = $messages;

        while (true) {
            $bodyJson = $this->buildBody($workingMessages, $system, $model, $maxTokens, $tools);
            if ($bodyJson === null) {
                error_log('[Ai] failed to encode request body');
                return AiResult::failure(AiFailureKind::BadResponse, 'Nie udało się zbudować żądania.');
            }

            $resp = $this->transport->send($url, $bodyJson, $headers, $timeout);
            [$result, $retryable, $rawContent] = $this->interpret($resp, $model);

            if (!$result->ok) {
                if (!$retryable || $attempt >= $maxRetries) {
                    return $result;
                }
                $delaySeconds = ($baseDelay * (2 ** $attempt)) / 1000.0;
                if (microtime(true) + $delaySeconds >= $deadline) {
                    return $result; // no budget left for another attempt
                }
                if ($delaySeconds > 0.0) {
                    usleep((int) ($delaySeconds * 1_000_000));
                }
                $attempt++;
                continue;
            }

            // change: cvs-ai-critical-review — server-side tool loop paused after
            // its iteration limit. Continue the conversation by appending the
            // assistant's own (unmodified) content blocks, no new user message.
            if ($result->stopReason === 'pause_turn'
                && $continuations < self::MAX_CONTINUATIONS
                && $rawContent !== null
                && microtime(true) < $deadline
            ) {
                $continuations++;
                $workingMessages[] = ['role' => 'assistant', 'content' => $rawContent];
                continue;
            }

            return $result;
        }
    }

    /**
     * @param list<array{role: string, content: mixed}> $messages
     * @param list<array<string, mixed>>                 $tools
     */
    private function buildBody(array $messages, ?CacheableSystem $system, string $model, int $maxTokens, array $tools = []): ?string
    {
        $body = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        if ($system !== null) {
            $body['system'] = [[
                'type'          => 'text',
                'text'          => $system->text,
                'cache_control' => ['type' => 'ephemeral', 'ttl' => $system->ttl],
            ]];
        }

        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        $json = json_encode($body);

        return $json === false ? null : $json;
    }

    /**
     * @return array<int, string>
     */
    private function buildHeaders(string $apiKey, string $version, ?CacheableSystem $system): array
    {
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . $version,
        ];

        if ($system !== null && $system->ttl === CacheableSystem::TTL_1H) {
            $headers[] = 'anthropic-beta: extended-cache-ttl-2025-04-11';
        }

        return $headers;
    }

    /**
     * Map a transport response to (AiResult, retryable, raw content blocks).
     *
     * Raw content blocks (3rd element) are the exact, unmodified `content`
     * array from a successful response — needed verbatim to continue a
     * pause_turn conversation (change: cvs-ai-critical-review). Null on
     * failure or when there's nothing to continue.
     *
     * @param array{status: int, body: string, error: string|null} $resp
     * @return array{0: AiResult, 1: bool, 2: list<array<string, mixed>>|null}
     */
    private function interpret(array $resp, string $model): array
    {
        if ($resp['error'] !== null) {
            $err  = strtolower($resp['error']);
            $kind = (str_contains($err, 'timed out') || str_contains($err, 'timeout'))
                ? AiFailureKind::Timeout
                : AiFailureKind::Network;
            error_log('[Ai] transport error: ' . $kind->value);
            return [AiResult::failure($kind, $this->message($kind)), true, null];
        }

        $status = $resp['status'];

        if ($status >= 200 && $status < 300) {
            return $this->parseSuccess($resp['body'], $model);
        }

        $decoded  = json_decode($resp['body'], true);
        $errType  = is_array($decoded) ? (string) (self::dig($decoded, 'error', 'type')) : '';
        $errMsg   = is_array($decoded) ? (string) (self::dig($decoded, 'error', 'message')) : '';
        $haystack = strtolower($errType . ' ' . $errMsg);

        $mapped = match (true) {
            $status === 429                              => [AiFailureKind::RateLimited, true],
            $status === 529                              => [AiFailureKind::Overloaded, true],
            in_array($status, [500, 502, 503, 504], true) => [AiFailureKind::Overloaded, true],
            $status === 401 || $status === 403           => [AiFailureKind::Auth, false],
            str_contains($haystack, 'credit')
                || str_contains($haystack, 'billing')
                || str_contains($haystack, 'quota')      => [AiFailureKind::Quota, false],
            default                                      => [AiFailureKind::BadResponse, false],
        };

        error_log('[Ai] api error status=' . $status . ' kind=' . $mapped[0]->value);
        return [AiResult::failure($mapped[0], $this->message($mapped[0])), $mapped[1], null];
    }

    /**
     * @return array{0: AiResult, 1: bool, 2: list<array<string, mixed>>|null}
     */
    private function parseSuccess(string $body, string $model): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('[Ai] non-JSON 2xx response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Nieprawidłowa odpowiedź modelu.'), false, null];
        }

        $content = $decoded['content'] ?? null;
        if (!is_array($content)) {
            error_log('[Ai] missing text content in response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Pusta odpowiedź modelu.'), false, null];
        }

        // change: cvs-ai-critical-review — a web-search-enabled response can
        // interleave text blocks with server_tool_use/web_search_tool_result
        // blocks. Concatenate every text block (in order) instead of taking
        // only the first, collect citations, and flag a degraded (but still
        // completed) search rather than failing the whole response.
        $textParts      = [];
        $citationsByUrl = [];
        $searchDegraded = false;

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;

            if ($type === 'text' && isset($block['text']) && is_string($block['text'])) {
                $textParts[] = $block['text'];
                foreach ((is_array($block['citations'] ?? null) ? $block['citations'] : []) as $c) {
                    if (is_array($c) && isset($c['url']) && is_string($c['url'])) {
                        $citationsByUrl[$c['url']] = [
                            'url'   => $c['url'],
                            'title' => is_string($c['title'] ?? null) ? $c['title'] : $c['url'],
                        ];
                    }
                }
                continue;
            }

            if ($type === 'web_search_tool_result') {
                // Success shape: 'content' is a LIST of result objects.
                // Error shape: 'content' is a single associative error object
                // (e.g. {"type": "web_search_tool_result_error", ...}) — no
                // exception, no non-2xx status, just this shape difference.
                $inner = $block['content'] ?? null;
                if (is_array($inner) && !array_is_list($inner)) {
                    $searchDegraded = true;
                }
                continue;
            }

            // change: cvs-ai-critical-review — observed live (2026-07-07):
            // some models route web_search through a code_execution sandbox
            // rather than calling it directly. That sandbox can itself fail
            // (a bug in the model's own generated glue code, or its internal
            // tool-use budget running out) even when the underlying
            // web_search_tool_result above was perfectly successful. A
            // non-zero return_code or non-empty stderr here means the search
            // results likely never reached the final answer.
            if ($type === 'code_execution_tool_result') {
                $inner = $block['content'] ?? null;
                if (is_array($inner)) {
                    $returnCode = $inner['return_code'] ?? null;
                    $stderr     = $inner['stderr'] ?? null;
                    if ((is_int($returnCode) && $returnCode !== 0) || (is_string($stderr) && $stderr !== '')) {
                        $searchDegraded = true;
                    }
                }
            }
        }

        // change: cvs-ai-critical-review — observed live (2026-07-07): citation
        // attachment can split ONE continuous sentence into several text
        // blocks (a citation applies to a specific span, not a whole
        // paragraph). Joining with any separator inserts spurious blank
        // lines mid-sentence. Direct concatenation reconstructs the intended
        // text exactly, whether blocks were split for citations or for a
        // natural "let me search, then continue" reasoning break — Claude's
        // own text already carries whatever whitespace it wants between them.
        $text = implode('', $textParts);
        if ($text === '') {
            error_log('[Ai] missing text content in response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Pusta odpowiedź modelu.'), false, null];
        }

        $usageRaw   = $decoded['usage'] ?? null;
        $usage      = is_array($usageRaw) ? AiUsage::fromApi($usageRaw) : new AiUsage(0, 0, 0, 0);
        $stopReason = is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : '';
        $respModel  = is_string($decoded['model'] ?? null) ? $decoded['model'] : $model;

        $result = AiResult::success($text, $usage, $stopReason, $respModel, array_values($citationsByUrl), $searchDegraded);

        return [$result, false, $content];
    }

    private function message(AiFailureKind $kind): string
    {
        return match ($kind) {
            AiFailureKind::Timeout     => 'Przekroczono czas oczekiwania na model.',
            AiFailureKind::RateLimited => 'Przekroczono limit zapytań — spróbuj ponownie później.',
            AiFailureKind::Overloaded  => 'Model chwilowo przeciążony — spróbuj ponownie później.',
            AiFailureKind::Auth        => 'Błąd uwierzytelnienia API.',
            AiFailureKind::Quota       => 'Wyczerpany limit/budżet API.',
            AiFailureKind::BadResponse => 'Nieprawidłowa odpowiedź modelu.',
            AiFailureKind::Network     => 'Błąd połączenia z modelem.',
        };
    }

    /**
     * Safely read a nested key from a decoded array.
     *
     * @param array<mixed> $arr
     */
    private static function dig(array $arr, string $outer, string $inner): string
    {
        $o = $arr[$outer] ?? null;
        if (is_array($o) && isset($o[$inner]) && is_scalar($o[$inner])) {
            return (string) $o[$inner];
        }
        return '';
    }
}
