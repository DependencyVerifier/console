<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A display name too long or not printable, an address that is not an IP, too many addresses.
 */
final class InvalidHostLabel extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('invalid_host_label', $detail);
    }
}
