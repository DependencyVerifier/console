<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Only a notification-only account is attached as a recipient: one that signs in is invited, or subscribes itself.
 */
final class RecipientSignsIn extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('recipient_signs_in');
    }
}
