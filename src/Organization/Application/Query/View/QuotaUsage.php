<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

use Organization\Domain\Model\Quota;

/**
 * A gauge: usage against a quota (warning from 80 %, may exceed 100 % after a lowered quota).
 */
final readonly class QuotaUsage
{
    public function __construct(public string $name, public ?int $limit, public int $usage, public bool $enforced)
    {
    }

    public function isWarning(): bool
    {
        return Quota::fromStorage($this->limit)->isWarning($this->usage);
    }

    public function isReached(): bool
    {
        return !Quota::fromStorage($this->limit)->allows($this->usage);
    }
}
