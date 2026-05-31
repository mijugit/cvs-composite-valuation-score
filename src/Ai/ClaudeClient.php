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

    /**
     * Send a Messages API request and return a typed result.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                        $options   e.g. ['max_tokens' => 1024]
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
        $maxTokens  = (int) ($options['max_tokens'] ?? $this->config['max_tokens'] ?? 2048);
        $timeout    = (int) ($this->config['timeout'] ?? 20);
        $maxRetries = (int) ($this->config['max_retries'] ?? 2);
        $budget     = (float) ($this->config['total_timeout'] ?? 25);
        $baseDelay  = (int) ($this->config['retry_base_delay_ms'] ?? 500);

        $bodyJson = $this->buildBody($messages, $system, $model, $maxTokens);
        if ($bodyJson === null) {
            error_log('[Ai] failed to encode request body');
            return AiResult::failure(AiFailureKind::BadResponse, 'Nie udało się zbudować żądania.');
        }
        $headers = $this->buildHeaders($apiKey, $version, $system);

        $deadline = microtime(true) + $budget;
        $attempt  = 0;

        while (true) {
            $resp = $this->transport->send($url, $bodyJson, $headers, $timeout);
            [$result, $retryable] = $this->interpret($resp, $model);

            if ($result->ok || !$retryable || $attempt >= $maxRetries) {
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

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function buildBody(array $messages, ?CacheableSystem $system, string $model, int $maxTokens): ?string
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
            return [$this->parseSuccess($resp['body'], $model), false];
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
        return [AiResult::failure($mapped[0], $this->message($mapped[0])), $mapped[1]];
    }

    private function parseSuccess(string $body, string $model): AiResult
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('[Ai] non-JSON 2xx response');
            return AiResult::failure(AiFailureKind::BadResponse, 'Nieprawidłowa odpowiedź modelu.');
        }

        $text    = null;
        $content = $decoded['content'] ?? null;
        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block)
                    && ($block['type'] ?? null) === 'text'
                    && isset($block['text'])
                    && is_string($block['text'])
                ) {
                    $text = $block['text'];
                    break;
                }
            }
        }

        if ($text === null || $text === '') {
            error_log('[Ai] missing text content in response');
            return AiResult::failure(AiFailureKind::BadResponse, 'Pusta odpowiedź modelu.');
        }

        $usageRaw    = $decoded['usage'] ?? null;
        $usage       = is_array($usageRaw) ? AiUsage::fromApi($usageRaw) : new AiUsage(0, 0, 0, 0);
        $stopReason  = is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : '';
        $respModel   = is_string($decoded['model'] ?? null) ? $decoded['model'] : $model;

        return AiResult::success($text, $usage, $stopReason, $respModel);
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
