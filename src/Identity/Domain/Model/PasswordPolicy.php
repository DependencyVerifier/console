<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use function Symfony\Component\String\u;

/**
 * Decision 5: 12 to 4096 characters, no composition rule, neither equal to nor containing the normalized email
 * (case-insensitive). No external call (no HIBP).
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 4096;

    public static function check(#[\SensitiveParameter] string $password, EmailAddress $email): void
    {
        $length = u($password)->length();
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new PasswordRejected();
        }

        if (u($password)->lower()->containsAny($email->value())) {
            throw new PasswordRejected();
        }
    }
}
