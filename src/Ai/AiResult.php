<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Value object returned by ClaudeClient — a success or a typed failure.
 *
 * Mirrors the CVSResult pattern (private constructor + named constructors +
 * public readonly props + toArray()). The client NEVER throws to the caller;
 * every error path becomes an AiResult::failure(...) so the detail page and the
 * CVS result keep rendering (phase-2 guardrail: AI failure must not break the page).
 *
 * Carries no secrets — the API key never appears on this object or in toArray().
 */
class AiResult
{
    public readonly bool           $ok;
    public readonly ?string        $text;
    public readonly ?AiUsage       $usage;
    public readonly ?string        $stopReason;
    public readonly ?string        $model;
    public readonly ?AiFailureKind $failureKind;
    /** Human-readable failure detail (Polish where user-facing). Null on success. */
    public readonly ?string        $failureMessage;
    /**
     * Deduplicated web search citations, change: cvs-ai-critical-review.
     * Empty for callers that never pass `tools` (etap 1 unaffected).
     *
     * @var list<array{url: string, title: string}>
     */
    public readonly array $citations;
    /**
     * True when a web search tool call degraded (e.g. max_uses_exceeded) but
     * the response still completed — change: cvs-ai-critical-review. Always
     * false for callers that never pass `tools`.
     */
    public readonly bool $searchDegraded;

    /**
     * @param list<array{url: string, title: string}> $citations
     */
    private function __construct(
        bool           $ok,
        ?string        $text,
        ?AiUsage       $usage,
        ?string        $stopReason,
        ?string        $model,
        ?AiFailureKind $failureKind,
        ?string        $failureMessage,
        array          $citations,
        bool           $searchDegraded,
    ) {
        $this->ok             = $ok;
        $this->text           = $text;
        $this->usage          = $usage;
        $this->stopReason     = $stopReason;
        $this->model          = $model;
        $this->failureKind    = $failureKind;
        $this->failureMessage = $failureMessage;
        $this->citations      = $citations;
        $this->searchDegraded = $searchDegraded;
    }

    // ------------------------------------------------------------------
    // Named constructors
    // ------------------------------------------------------------------

    /**
     * @param list<array{url: string, title: string}> $citations
     */
    public static function success(
        string  $text,
        AiUsage $usage,
        string  $stopReason,
        string  $model,
        array   $citations = [],
        bool    $searchDegraded = false,
    ): self {
        return new self(
            ok:             true,
            text:           $text,
            usage:          $usage,
            stopReason:     $stopReason,
            model:          $model,
            failureKind:    null,
            failureMessage: null,
            citations:      $citations,
            searchDegraded: $searchDegraded,
        );
    }

    public static function failure(AiFailureKind $kind, string $message): self
    {
        return new self(
            ok:             false,
            text:           null,
            usage:          null,
            stopReason:     null,
            model:          null,
            failureKind:    $kind,
            failureMessage: $message,
            citations:      [],
            searchDegraded: false,
        );
    }

    // ------------------------------------------------------------------
    // Serialisation
    // ------------------------------------------------------------------

    /**
     * Serialise for JSON responses / template rendering. Contains no secrets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok'              => $this->ok,
            'text'            => $this->text,
            'usage'           => $this->usage?->toArray(),
            'stop_reason'     => $this->stopReason,
            'model'           => $this->model,
            'failure_kind'    => $this->failureKind?->value,
            'failure_message' => $this->failureMessage,
            'citations'       => $this->citations,
            'search_degraded' => $this->searchDegraded,
        ];
    }
}
