<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An order payload out of the catalogue bounds (protocol §7.3).
 */
final class InvalidOrderPayload extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_order_payload');
    }
}
