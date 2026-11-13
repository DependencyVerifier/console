<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Crypto;

use Fleet\Application\Port\ConsoleSigner;
use Fleet\Domain\Model\AgentPublicKey;
use Shared\Infrastructure\Crypto\Ed25519Seed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The console key from APP_AGENT_SIGNING_KEY: the standard base64 of a 32-byte Ed25519 seed (generate one with
 * `openssl rand -base64 32`). Fail-closed: a missing or malformed seed refuses to build, and SecretsBootCheck checks
 * it before the first request outside dev and test. Changing it means re-installing every agent (they pin the key).
 */
final readonly class SodiumConsoleSigner implements ConsoleSigner
{
    private string $secretKey;

    private AgentPublicKey $publicKey;

    private ?string $nextSecretKey;

    private ?AgentPublicKey $nextKey;

    public function __construct(
        #[Autowire(env: 'APP_AGENT_SIGNING_KEY')] #[\SensitiveParameter] string $seed,
        #[Autowire(env: 'APP_AGENT_SIGNING_KEY_NEXT')] #[\SensitiveParameter] string $nextSeed = '',
    ) {
        $keyPair = sodium_crypto_sign_seed_keypair(Ed25519Seed::decode($seed, 'APP_AGENT_SIGNING_KEY'));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        $this->publicKey = AgentPublicKey::fromRaw(sodium_crypto_sign_publickey($keyPair));
        $next = '' === $nextSeed ? null : sodium_crypto_sign_seed_keypair(Ed25519Seed::decode($nextSeed, 'APP_AGENT_SIGNING_KEY_NEXT'));
        $this->nextSecretKey = null === $next ? null : sodium_crypto_sign_secretkey($next);
        $this->nextKey = null === $next ? null : AgentPublicKey::fromRaw(sodium_crypto_sign_publickey($next));
    }

    public function nextKey(): ?AgentPublicKey
    {
        return $this->nextKey;
    }

    public function signWith(string $fingerprint, string $message): ?string
    {
        return match ($fingerprint) {
            $this->publicKey->fingerprint() => sodium_crypto_sign_detached($message, $this->secretKey),
            $this->nextKey?->fingerprint() => null === $this->nextSecretKey ? null : sodium_crypto_sign_detached($message, $this->nextSecretKey),
            default => null,
        };
    }

    public function publicKey(): AgentPublicKey
    {
        return $this->publicKey;
    }

    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }
}
