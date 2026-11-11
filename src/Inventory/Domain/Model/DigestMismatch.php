<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The uploaded snapshot does not hash to its digest (protocol §8.3).
 */
final class DigestMismatch extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('digest_mismatch', $detail);
    }
}
