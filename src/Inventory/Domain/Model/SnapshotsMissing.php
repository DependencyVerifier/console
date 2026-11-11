<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * `complete` before every snapshot announced missing was uploaded (protocol §8.4): the agent uploads the listed ones.
 */
final class SnapshotsMissing extends BusinessRuleViolated
{
    /**
     * @param list<string> $digests
     */
    public function __construct(public readonly array $digests)
    {
        parent::__construct('missing_snapshots');
    }
}
