<?php

declare(strict_types=1);

namespace Shared\Application\Crypto;

/**
 * Authenticated encryption of secrets read back later (TOTP secrets…). The additional data binds a ciphertext to
 * its field and owner, so it cannot be copied to another row.
 */
interface SecretBox
{
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $additionalData): string;

    public function decrypt(string $ciphertext, string $additionalData): string;
}
