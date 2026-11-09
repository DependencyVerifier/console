<?php

declare(strict_types=1);

namespace Identity\Application\Query\SecondFactor;

use Identity\Domain\Model\CodeVerificationOutcome;
use Shared\Application\Bus\Query;

/**
 * Outcome of a code verification command, read back after the dispatch (the command itself never throws for a
 * wrong code).
 *
 * @implements Query<CodeVerificationOutcome>
 */
final readonly class GetCodeVerificationOutcome implements Query
{
    public function __construct(public string $attemptId)
    {
    }
}
