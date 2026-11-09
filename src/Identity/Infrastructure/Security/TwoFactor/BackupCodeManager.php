<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security\TwoFactor;

use Identity\Application\Command\SecondFactor\UseBackupCode;
use Identity\Infrastructure\Security\SecurityUser;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * isBackupCode() checks and consumes in one gesture under the User lock (UseBackupCode); invalidateBackupCode() is
 * empty on purpose. scheb tries every submitted code as a backup code first: only the `xxxxx-xxxxx` format is
 * dispatched, so a wrong TOTP is never counted twice by the limiters.
 */
final readonly class BackupCodeManager implements BackupCodeManagerInterface
{
    public function __construct(private CodeVerifyingProvider $verifier)
    {
    }

    public function isBackupCode(object $user, string $code): bool
    {
        if (!$user instanceof SecurityUser || !$user->isTotpActive() || [] === u($code)->trim()->lower()->match('/^[a-z0-9]{5}-[a-z0-9]{5}$/')) {
            return false;
        }

        return $this->verifier->verify(new UseBackupCode($user->id, $code, Uuid::v7()->toRfc4122()));
    }

    public function invalidateBackupCode(object $user, string $code): void
    {
        // Consumed by isBackupCode() already.
    }
}
