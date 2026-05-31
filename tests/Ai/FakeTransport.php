<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\HttpTransport;

/**
 * Offline HttpTransport for tests — returns a queue of canned responses.
 *
 * Each call to send() pops the next scripted response (so a 429-then-200
 * sequence can be simulated). Records every request for assertions (e.g. that
 * cache_control / beta headers were set, and that the API key was sent only in
 * the header).
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{status: int, body: string, error: string|null}> */
    private array $responses;

    /** @var list<array{url: string, body: string, headers: array<int, string>, timeout: int}> */
    public array $requests = [];

    /** @param list<array{status: int, body: string, error: string|null}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(string $url, string $jsonBody, array $headers, int $timeout): array
    {
        $this->requests[] = ['url' => $url, 'body' => $jsonBody, 'headers' => $headers, 'timeout' => $timeout];

        $next = array_shift($this->responses);
        if ($next === null) {
            return ['status' => 0, 'body' => '', 'error' => 'FakeTransport: no scripted response left'];
        }

        return $next;
    }
}
