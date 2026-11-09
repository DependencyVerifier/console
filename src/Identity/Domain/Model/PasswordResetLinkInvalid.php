<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The reset link is expired, already used or replaced by a newer one.
 */
final class PasswordResetLinkInvalid extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('password_reset_link_invalid');
    }
}
