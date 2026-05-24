<?php

declare(strict_types=1);

namespace CVS\CVS;

/**
 * Value object returned by QualityGate::evaluate().
 */
readonly class QualityGateResult
{
    /**
     * @param bool     $passed   True when no gate criterion was breached.
     * @param string[] $failures Human-readable failure messages (Polish).
     */
    public function __construct(
        public bool  $passed,
        public array $failures
    ) {}
}
