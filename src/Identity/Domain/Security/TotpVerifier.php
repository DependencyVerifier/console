<?php

declare(strict_types=1);

namespace Identity\Domain\Security;

use Identity\Domain\Model\UserId;

/**
 * Finds the 30-second time step a TOTP code belongs to (one step of leeway on each side), or null when the code
 * matches none. The secret stays encrypted in the aggregate; the implementation decrypts it.
 */
interface TotpVerifier
{
    public function matchingStep(string $encryptedSecret, UserId $owner, #[\SensitiveParameter] string $code, \DateTimeImmutable $now): ?int;
}
