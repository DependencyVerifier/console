<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An inventory that does not follow the protocol (protocol §8.2): digest, subject or manifest field.
 */
final class InvalidInventory extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('invalid_payload', $detail);
    }
}
