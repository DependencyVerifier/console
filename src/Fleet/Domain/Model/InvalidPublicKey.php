<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

final class InvalidPublicKey extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_public_key');
    }
}
