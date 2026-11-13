<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

/**
 * A passkey the account holder's authenticator just created, once its attestation is verified (by the
 * PasskeyCeremony port): its credential id (base64url), its public key (COSE, base64), its signature counter, its
 * transports and the model of its authenticator (AAGUID).
 */
final readonly class NewPasskey
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $credentialId,
        public string $publicKey,
        public int $signCount,
        public array $transports,
        public string $aaguid,
    ) {
    }
}
