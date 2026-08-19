<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Gemini `generateContent` REST client (Google AI Studio / Generative Language API).
 *
 * Mirrors ClaudeClient's public contract exactly (sendMessage(messages, ?system,
 * options): AiResult) so callers built against one client can be copied to the
 * other with only the client class swapped — see change: llm-gemini-wallet.
 *
 * Differences from ClaudeClient, all provider-inherent (not stylistic choices):
 *  - Auth is a header (`x-goog-api-key`), not `x-api-key`.
 *  - The model ID is part of the URL path, not a body field.
 *  - Request/response shape is `contents`/`systemInstruction`/`generationConfig` and
 *    `candidates[0].content.parts[]`/`usageMetadata`/`groundingMetadata`, not `messages`/
 *    `system`/`content[]`.
 *  - $system->ttl is accepted for signature parity but ignored — Gemini has no
 *    `cache_control` equivalent (see plan.md Critical Implementation Details).
 *  - No continuation loop: Gemini has no `pause_turn` equivalent for our single-turn
 *    call sites (the decision call and the context-gatherer's search call), so this
 *    client is simpler than ClaudeClient in that one respect.
 *
 * NEVER throws — every error path returns AiResult::failure(...). Network is behind
 * the same HttpTransport seam as ClaudeClient, reused unchanged.
 */
final class GeminiClient
{
    /** @param array<string, mixed> $config  config/gemini.php contents */
    public function __construct(
        private readonly array $config,
        private readonly HttpTransport $transport,
    ) {}

    /**
     * Send a generateContent request and return a typed result.
     *
     * @param list<array{role: string, content: mixed}> $messages
     * @param array<string, mixed>                        $options   e.g. ['max_tokens' => 1024, 'tools' => [...]]
     */
    public function sendMessage(array $messages, ?CacheableSystem $system = null, array $options = []): AiResult
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            error_log('[Ai] missing Gemini_CVS');
            return AiResult::failure(AiFailureKind::Auth, 'Brak klucza API — analiza AI niedostępna.');
        }

        $baseUrl    = (string) ($this->config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta');
        $model      = (string) ($this->config['model'] ?? '');
        $url        = $this->buildUrl($baseUrl, $model);
        $maxTokens  = max(1, (int) ($options['max_tokens'] ?? $this->config['max_tokens'] ?? 2048));
        $timeout    = (int) ($this->config['timeout'] ?? 20);
        $maxRetries = (int) ($this->config['max_retries'] ?? 2);
        $budget     = (float) ($this->config['total_timeout'] ?? 25);
        $baseDelay  = (int) ($this->config['retry_base_delay_ms'] ?? 500);
        /** @var list<array<string, mixed>> $tools */
        $tools      = is_array($options['tools'] ?? null) ? $options['tools'] : [];

        $bodyJson = $this->buildBody($messages, $system, $maxTokens, $tools);
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

    private function buildUrl(string $baseUrl, string $model): string
    {
        return rtrim($baseUrl, '/') . '/models/' . $model . ':generateContent';
    }

    /**
     * @param list<array{role: string, content: mixed}> $messages
     * @param list<array<string, mixed>>                 $tools
     */
    private function buildBody(array $messages, ?CacheableSystem $system, int $maxTokens, array $tools): ?string
    {
        $contents = array_map(
            static fn (array $m): array => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ],
            $messages
        );

        $body = [
            'contents'         => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];

        if ($system !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $system->text]]];
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
            'x-goog-api-key: ' . $apiKey,
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
        $errType  = is_array($decoded) ? (string) (self::dig($decoded, 'error', 'status')) : '';
        $errMsg   = is_array($decoded) ? (string) (self::dig($decoded, 'error', 'message')) : '';
        $haystack = strtolower($errType . ' ' . $errMsg);

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

        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            error_log('[Ai] missing candidates in response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Pusta odpowiedź modelu.'), false];
        }

        $first   = is_array($candidates[0]) ? $candidates[0] : [];
        $content = $first['content'] ?? null;
        $parts   = is_array($content) ? ($content['parts'] ?? []) : [];

        $textParts = [];
        foreach ((is_array($parts) ? $parts : []) as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $textParts[] = $part['text'];
            }
        }
        $text = implode('', $textParts);

        if ($text === '') {
            error_log('[Ai] missing text content in response');
            return [AiResult::failure(AiFailureKind::BadResponse, 'Pusta odpowiedź modelu.'), false];
        }

        $usageRaw = $decoded['usageMetadata'] ?? null;
        $usage    = is_array($usageRaw)
            ? new AiUsage((int) ($usageRaw['promptTokenCount'] ?? 0), (int) ($usageRaw['candidatesTokenCount'] ?? 0), 0, 0)
            : new AiUsage(0, 0, 0, 0);

        $finishReason = is_string($first['finishReason'] ?? null) ? $first['finishReason'] : '';
        $respModel    = is_string($decoded['modelVersion'] ?? null) ? $decoded['modelVersion'] : $model;

        $groundingMetadata = $first['groundingMetadata'] ?? null;
        $chunks            = is_array($groundingMetadata) ? ($groundingMetadata['groundingChunks'] ?? []) : [];
        $citationsByUrl    = [];
        foreach ((is_array($chunks) ? $chunks : []) as $chunk) {
            $web = is_array($chunk) ? ($chunk['web'] ?? null) : null;
            if (is_array($web) && isset($web['uri']) && is_string($web['uri'])) {
                $citationsByUrl[$web['uri']] = [
                    'url'   => $web['uri'],
                    'title' => is_string($web['title'] ?? null) ? $web['title'] : $web['uri'],
                ];
            }
        }

        $result = AiResult::success($text, $usage, $finishReason, $respModel, array_values($citationsByUrl), false);

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
