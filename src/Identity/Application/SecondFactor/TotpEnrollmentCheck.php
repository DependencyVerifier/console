<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Identity\Application\Command\SecondFactor\ConfirmTotp;
use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\User;
use Identity\Domain\Security\TotpVerifier;
use Shared\Application\Crypto\TokenHasher;

/**
 * Confirmation of a pending enrolment: calls User::confirmTotp (verifyTotp would be an invalid transition on a
 * pending TOTP), with the hashes of the backup codes the UI generated.
 */
final readonly class TotpEnrollmentCheck implements CodeCheck
{
    public function __construct(private TotpVerifier $verifier, private TokenHasher $hasher)
    {
    }

    public function kind(): CodeKind
    {
        return CodeKind::TotpEnrollment;
    }

    public function verify(User $user, CodeVerification $command, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        if (!$command instanceof ConfirmTotp) {
            throw new \InvalidArgumentException(\sprintf('%s only checks %s.', self::class, ConfirmTotp::class));
        }

        $hashes = array_map($this->hasher->hash(...), $command->backupCodes);

        return $user->confirmTotp($command->code, $this->verifier, $hashes, $now);
    }
}
