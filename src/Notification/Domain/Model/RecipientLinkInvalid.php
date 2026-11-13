<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A confirmation link unknown, expired, or of a subscription already confirmed by another link.
 */
final class RecipientLinkInvalid extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('recipient_link_invalid');
    }
}
