<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Crypto;

use Fleet\Application\Port\SignatureVerifier;
use Fleet\Domain\Model\AgentPublicKey;

use function Symfony\Component\String\b;

final readonly class SodiumSignatureVerifier implements SignatureVerifier
{
    public function verify(string $signature, string $message, AgentPublicKey $key): bool
    {
        $raw = $key->raw;
        if ('' === $signature || '' === $raw || \SODIUM_CRYPTO_SIGN_BYTES !== b($signature)->length()) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $raw);
    }
}
