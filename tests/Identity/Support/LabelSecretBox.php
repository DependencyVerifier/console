<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Crypto\SecretBox;

final class LabelSecretBox implements SecretBox
{
    public function encrypt(string $plaintext, string $additionalData): string
    {
        return 'enc['.$additionalData.']:'.$plaintext;
    }

    public function decrypt(string $ciphertext, string $additionalData): string
    {
        return $ciphertext;
    }
}
