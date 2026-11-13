<?php

declare(strict_types=1);

namespace Identity\Application\Port;

/**
 * What an assertion says of itself before any check: the credential used and the account it belongs to (its user
 * handle). Untrusted until PasskeyCeremony::verifyAssertion() holds.
 */
final readonly class PasskeyClaim
{
    public function __construct(public string $credentialId, public string $userId)
    {
    }
}
