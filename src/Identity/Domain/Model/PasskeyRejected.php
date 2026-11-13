<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A WebAuthn response that does not hold: wrong origin or challenge, user not verified, bad signature, malformed.
 */
final class PasskeyRejected extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('passkey_rejected');
    }
}
