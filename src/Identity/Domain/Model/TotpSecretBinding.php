<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

/**
 * Additional authenticated data of an encrypted TOTP secret: the field and its owner, so a ciphertext copied to
 * another account does not decrypt.
 */
final class TotpSecretBinding
{
    public static function for(UserId $owner): string
    {
        return 'identity.totp_secret:'.$owner->toString();
    }
}
