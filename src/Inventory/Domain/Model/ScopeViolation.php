<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A compose / labeled agent sent a subject outside its Compose project, or the host itself (protocol §8.4).
 */
final class ScopeViolation extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('scope_violation', $detail);
    }
}
