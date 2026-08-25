<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * OpenAI Responses API client (`POST /v1/responses`) — change: critical-review-openai.
 *
 * Mirrors ClaudeClient's/GeminiClient's public contract exactly
 * (`sendMessage(messages, ?system, options): AiResult`) so this client can be
 * dropped into the same call sites with only the client class swapped.
 * Flavor-agnostic: this class knows nothing about "Terra" vs "Luna" — it just
 * takes whatever flat config (api_key, model, ...) it's constructed with, the
 * same way ClaudeClient/GeminiClient don't know which product tier they serve.
 * See GPTClientFactory for the flavor selection.
 *
 * Differences from GeminiClient, all provider-inherent (not stylistic choices):
 *  - Auth is `Authorization: Bearer <key>`, not a custom header name.
 *  - Request shape: `instructions`/`input`/`tools`/`reasoning.effort`/
 *    `max_output_tokens` (Responses API), not `contents`/`systemInstruction`/
 *    `generationConfig`.
 *  - Response shape: generated text lives at `output[].content[].text` for
 *    `output[]` items where `type === 'message'` (other item types, e.g. a
 *    web-search tool-call record, can appear in the same array and must be
 *    skipped) and `content[]` items where `type === 'output_text'`. Usage is
 *    `usage.input_tokens`/`usage.output_tokens` (no cache-token fields, same
 *    as Gemini — AiUsage's last two constructor args are always 0).
 *  - Citations are attempted from `annotations[].type === 'url_citation'` on
 *    each output_text content item (deduped by URL, mirroring GeminiClient's
 *    groundingChunks dedup) — this shape was NOT independently confirmed
 *    against a live response, so it degrades to an empty list on any mismatch
 *    rather than failing (never a hard requirement).
 *  - searchDegraded is always false — no known degraded-search signal for
 *    this provider (same simplification as GeminiClient).
 *
 * NEVER throws — every error path returns AiResult::failure(...). Network is
 * behind the same HttpTransport seam as ClaudeClient/GeminiClient, reused
 * unchanged.
 */
final class GPTClient
{
    /** @param array<string, mixed> $config  flat config/gpt.php slice (see GPTClientFactory) */
    public function __construct(
        private readonly array $config,
        private readonly HttpTransport $transport,
    ) {}

    /**
     * Send a Responses API request and return a typed result.
     *
     * @param list<array{role: string, content: mixed}> $messages
     * @param array<string, mixed>                        $options   e.g. ['max_tokens' => 8000, 'tools' => [...]]
     */
    public function sendMessage(array $messages, ?CacheableSystem $system = null, array $options = []): AiResult
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            error_log('[Ai] missing GPT API key');
            return AiResult::failure(AiFailureKind::Auth, 'Brak klucza API — analiza AI niedostępna.');
        }

        $url        = (string) ($this->config['base_url'] ?? 'https://api.openai.com/v1/responses');
        $model      = (string) ($this->config['model'] ?? '');
        $maxTokens  = max(1, (int) ($options['max_tokens'] ?? $this->config['max_tokens'] ?? 2048));
        $timeout    = (int) ($this->config['timeout'] ?? 20);
        $maxRetries = (int) ($this->config['max_retries'] ?? 2);
        $budget     = (float) ($this->config['total_timeout'] ?? 25);
        $baseDelay  = (int) ($this->config['retry_base_delay_ms'] ?? 500);
        $effort     = (string) ($this->config['reasoning_effort'] ?? 'medium');
        /** @var list<array<string, mixed>> $tools */
        $tools      = is_array($options['tools'] ?? null) ? $options['tools'] : [];

        $bodyJson = $this->buildBody($messages, $system, $model, $maxTokens, $effort, $tools);
        if ($bodyJson === null) {
            error_log('[Ai] failed to encode request body');
            return AiResult::failure(AiFailureKind::BadResponse, 'Nie udało się zbudować żądania.');
        }

        $headers  = $this->buildHeaders($apiKey);
        $deadline = microtime(true) + $budget;
        $attempt  = 0;

        while (true) {
            $resp = $this->transport->send($url, $bodyJson, $headers, $timeout);
            [$result, $retryable] = $this->interpret($resp, $model);

            if ($result->ok) {
                return $result;
            }

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
        }
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    /**
     * @param list<array{role: string, content: mixed}> $messages
     * @param list<array<string, mixed>>                 $tools
     */
    private function buildBody(array $messages, ?CacheableSystem $system, string $model, int $maxTokens, string $effort, array $tools): ?string
    {
        $input = array_map(
            static fn (array $m): array => [
                'role'    => $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) ($m['content'] ?? ''),
            ],
            $messages
        );

        $body = [
            'model'             => $model,
            'input'             => $input,
            'max_output_tokens' => $maxTokens,
            'reasoning'         => ['effort' => $effort],
        ];

        if ($system !== null) {
            $body['instructions'] = $system->text;
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
    private function buildHeaders(string $apiKey): array
    {
        return [
            'content-type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
    }

    /**
     * Map a transport response to (AiResult, retryable).
     *
     * @param array{status: int, body: string, error: string|null} $resp
     * @return array{0: AiResult, 1: bool}
     */
    private function interpret(array $resp, string $model): array
    {
        if ($resp['error'] !== null) {
            $err  = strtolower($resp['error']);
            $kind = (str_contains($err, 'timed out') || str_contains($err, 'timeout'))
                ? AiFailureKind::Timeout
                : AiFailureKind::Network;
            error_log('[Ai] transport error: ' . $kind->value);
            return [AiResult::failure($kind, $this->message($kind)), true];
        }

        $status = $resp['status'];

        if ($status >= 200 && $status < 300) {
            return $this->parseSuccess($resp['body'], $model);
        }

        $decoded  = json_decode($resp['body'], true);
        $errType  = is_array($decoded) ? (string) (self::dig($decoded, 'error', 'type')) : '';
        $errCode  = is_array($decoded) ? (string) (self::dig($decoded, 'error', 'code')) : '';
        $errMsg   = is_array($decoded) ? (string) (self::dig($decoded, 'error', 'message')) : '';
        $haystack = strtolower($errType . ' ' . $errCode . ' ' . $errMsg);

        $mapped = match (true) {
            $status === 429                         => [AiFailureKind::RateLimited, true],
            in_array($status, [500, 502, 503], true) => [AiFailureKind::Overloaded, true],
            $status === 504                          => [AiFailureKind::Timeout, true],
            $status === 401 || $status === 403       => [AiFailureKind::Auth, false],
            str_contains($haystack, 'quota')
                || str_contains($haystack, 'billing') => [AiFailureKind::Quota, false],
            default                                   => [AiFailureKind::BadResponse, false],
        };

        error_log('[Ai] api error status=' . $status . ' kind=' . $mapped[0]->value);
        return [AiResult::failure($mapped[0], $this->message($mapped[0])), $mapped[1]];
    }

    /**
     * @return array{0: AiResult, 1: bool}
     */
    private function parseSuccess(string $body, string $model): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('[Ai] non-JSON 2xx response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Nieprawidłowa odpowiedź modelu.'), false];
        }

        $output = $decoded['output'] ?? null;
        if (!is_array($output) || $output === []) {
            error_log('[Ai] missing output in response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Pusta odpowiedź modelu.'), false];
        }

        $textParts      = [];
        $citationsByUrl = [];
        foreach ($output as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            $content = $item['content'] ?? [];
            foreach ((is_array($content) ? $content : []) as $part) {
                if (!is_array($part) || ($part['type'] ?? null) !== 'output_text') {
                    continue;
                }
                if (is_string($part['text'] ?? null)) {
                    $textParts[] = $part['text'];
                }

                $annotations = $part['annotations'] ?? [];
                foreach ((is_array($annotations) ? $annotations : []) as $ann) {
                    if (is_array($ann) && ($ann['type'] ?? null) === 'url_citation' && is_string($ann['url'] ?? null)) {
                        $citationsByUrl[$ann['url']] = [
                            'url'   => $ann['url'],
                            'title' => is_string($ann['title'] ?? null) ? $ann['title'] : $ann['url'],
                        ];
                    }
                }
            }
        }
        $text = implode('', $textParts);

        if ($text === '') {
            error_log('[Ai] missing text content in response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Pusta odpowiedź modelu.'), false];
        }

        $usageRaw = $decoded['usage'] ?? null;
        $usage    = is_array($usageRaw)
            ? new AiUsage((int) ($usageRaw['input_tokens'] ?? 0), (int) ($usageRaw['output_tokens'] ?? 0), 0, 0)
            : new AiUsage(0, 0, 0, 0);

        $stopReason = is_string($decoded['status'] ?? null) ? $decoded['status'] : '';
        $respModel  = is_string($decoded['model'] ?? null) ? $decoded['model'] : $model;

        $result = AiResult::success($text, $usage, $stopReason, $respModel, array_values($citationsByUrl), false);

        return [$result, false];
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
