<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Agent;

use Fleet\Application\Query\View\EnrollmentAnswer;
use Shared\Application\Bus\Query;

/**
 * The answer to an enrollment, read after EnrollAgent: the live agent holding this key.
 *
 * @implements Query<EnrollmentAnswer>
 */
final readonly class FindEnrolledAgent implements Query
{
    public function __construct(public string $publicKey)
    {
    }
}
