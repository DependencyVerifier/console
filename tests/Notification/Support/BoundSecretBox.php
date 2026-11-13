<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Shared\Application\Crypto\SecretBox;

use function Symfony\Component\String\u;

/**
 * A readable "encryption" that still refuses a ciphertext of another binding.
 */
final class BoundSecretBox implements SecretBox
{
    public function encrypt(string $plaintext, string $additionalData): string
    {
        return 'enc['.$additionalData.']:'.$plaintext;
    }

    public function decrypt(string $ciphertext, string $additionalData): string
    {
        $prefix = 'enc['.$additionalData.']:';

        return u($ciphertext)->startsWith($prefix) ? u($ciphertext)->after($prefix)->toString() : throw new \RuntimeException('Wrong binding.');
    }
}
