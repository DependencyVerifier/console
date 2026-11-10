<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Symfony\Component\Uid\Uuid;

final class Users
{
    public const string ID = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    public const string OTHER_ID = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    public const string THIRD_ID = '019a6b2c-1f00-7a11-8b22-3c44d55e6f03';

    public static function member(string $id = self::ID, string $email = 'jane@example.com'): User
    {
        return User::activateFromInvitation(UserId::fromString($id), EmailAddress::of($email), 'password-hash', new \DateTimeImmutable('2026-11-09 08:00:00'));
    }

    public static function superAdmin(string $id = self::ID, string $email = 'root@example.com'): User
    {
        return User::createSuperAdmin(UserId::fromString($id), EmailAddress::of($email), 'password-hash', new \DateTimeImmutable('2026-11-09 08:00:00'));
    }

    /**
     * Active TOTP (step 100) with backup codes `aaaaa-11111` and `bbbbb-22222`.
     */
    public static function withTotp(User $user): User
    {
        $user->startTotpEnrollment('encrypted-secret');
        $user->confirmTotp('123456', new MappedTotpVerifier(['123456' => 100]), [PrefixMatcher::hash('aaaaa-11111'), PrefixMatcher::hash('bbbbb-22222')], new \DateTimeImmutable('2026-11-09 08:00:00'));
        $user->releaseEvents();

        return $user;
    }

    public static function uuid(): Uuid
    {
        return Uuid::v7();
    }
}
