<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Crypto;

use Shared\Application\Crypto\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * AES-256-GCM, stored as `v1:<kid>:base64(nonce ‖ tag ‖ ciphertext)`. The additional data (field + owner id) must
 * match on decryption, so a secret copied to another row does not decrypt.
 */
final readonly class AesGcmSecretBox implements SecretBox
{
    private const string CIPHER = 'aes-256-gcm';
    private const int NONCE_BYTES = 12;
    private const int TAG_BYTES = 16;

    private KeyRing $keys;

    public function __construct(#[Autowire(env: 'APP_ENCRYPTION_KEYS')] #[\SensitiveParameter] string $keys)
    {
        $this->keys = KeyRing::fromString($keys, 'APP_ENCRYPTION_KEYS');
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $additionalData): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->keys->current(), \OPENSSL_RAW_DATA, $nonce, $tag, $additionalData, self::TAG_BYTES);
        if (false === $ciphertext) {
            throw new \RuntimeException('Encryption failed.');
        }

        return \sprintf('v1:%s:%s', $this->keys->currentKid, base64_encode($nonce.$tag.$ciphertext));
    }

    public function decrypt(string $ciphertext, string $additionalData): string
    {
        $parts = u($ciphertext)->split(':', 3);
        if (3 !== \count($parts) || 'v1' !== $parts[0]->toString()) {
            throw new \InvalidArgumentException('Unknown ciphertext format.');
        }

        $key = $this->keys->get($parts[1]->toString()) ?? throw new \InvalidArgumentException('Unknown encryption key id.');
        $raw = base64_decode($parts[2]->toString(), true);
        if (false === $raw || b($raw)->length() <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new \InvalidArgumentException('Malformed ciphertext.');
        }

        $plaintext = openssl_decrypt(
            b($raw)->slice(self::NONCE_BYTES + self::TAG_BYTES)->toString(),
            self::CIPHER,
            $key,
            \OPENSSL_RAW_DATA,
            b($raw)->slice(0, self::NONCE_BYTES)->toString(),
            b($raw)->slice(self::NONCE_BYTES, self::TAG_BYTES)->toString(),
            $additionalData,
        );

        return false !== $plaintext ? $plaintext : throw new \RuntimeException('Decryption failed (wrong key or tampered data).');
    }
}
