<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Crypto;

use function Symfony\Component\String\b;

/**
 * An Ed25519 seed given as standard base64 of 32 random bytes (APP_AGENT_SIGNING_KEY): fail-closed decoding, shared
 * by the boot check and the console signer.
 */
final class Ed25519Seed
{
    /**
     * @return non-empty-string
     */
    public static function decode(#[\SensitiveParameter] string $encoded, string $name): string
    {
        $seed = base64_decode(b($encoded)->trim()->toString(), true);
        if (false === $seed || '' === $seed || \SODIUM_CRYPTO_SIGN_SEEDBYTES !== b($seed)->length()) {
            throw new \InvalidArgumentException(\sprintf('%s must be the base64 of a 32-byte Ed25519 seed.', $name));
        }

        return $seed;
    }
}
