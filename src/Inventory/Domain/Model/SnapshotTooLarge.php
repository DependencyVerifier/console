<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A snapshot beyond 20 MB compressed or 200 MB decompressed (protocol §8.3).
 */
final class SnapshotTooLarge extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('payload_too_large', $detail);
    }
}
