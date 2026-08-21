<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use CVS\CVS\CVSResult;

/**
 * Outcome of SingleTickerRescorer::rescore() — change: fundamentals-validation.
 */
final class SingleTickerRescoreResult
{
    public function __construct(
        public readonly bool $qualityGatePassed,
        public readonly CVSResult $cvsResult,
        public readonly ?float $fairValue,
    ) {
    }
}
