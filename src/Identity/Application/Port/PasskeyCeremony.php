<?php

declare(strict_types=1);

namespace Identity\Application\Port;

use Identity\Domain\Model\NewPasskey;
use Identity\Domain\Model\PasskeyCredential;
use Identity\Domain\Model\PasskeyRejected;

/**
 * WebAuthn (docs/cadrage.md §7): the relying party is the instance (`rpId` = the host of its URL), discoverable
 * credentials preferred, user verification required (a passkey alone is a multi-factor authentication), no
 * attestation asked. The adapter checks origin, challenge, flags and signature; the counter is the aggregate's.
 */
interface PasskeyCeremony
{
    /**
     * @param list<string> $excluded the credential ids the account already holds
     */
    public function creationOptions(string $userId, string $userName, string $displayName, array $excluded): PasskeyOptions;

    /**
     * @throws PasskeyRejected
     */
    public function verifyRegistration(string $response, PasskeyOptions $options): NewPasskey;

    /**
     * @param list<string> $allowed the credential ids of the account, none to let the authenticator choose
     */
    public function requestOptions(array $allowed): PasskeyOptions;

    public function claimOf(string $response): ?PasskeyClaim;

    /**
     * @throws PasskeyRejected
     *
     * @return int the signature counter the authenticator reports
     */
    public function verifyAssertion(string $response, PasskeyOptions $options, PasskeyCredential $credential, string $userId): int;
}
