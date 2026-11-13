<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Identity\Application\Port\PasskeyCeremony;
use Identity\Application\Port\PasskeyOptions;
use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\PasskeyCredential;
use Identity\Domain\Model\PasskeyRejected;
use Identity\Domain\Model\User;

/**
 * A passkey of the account signed the challenge: the ceremony checks the assertion, the aggregate its counter.
 */
final readonly class PasskeyCheck implements CodeCheck
{
    public function __construct(private PasskeyCeremony $ceremony, private CodeKind $kind = CodeKind::Passkey)
    {
    }

    public function forLogin(): self
    {
        return new self($this->ceremony, CodeKind::PasskeyLogin);
    }

    public function kind(): CodeKind
    {
        return $this->kind;
    }

    public function verify(User $user, CodeVerification $command, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        $claim = $this->ceremony->claimOf($command->code);
        $credential = null === $claim ? null : array_find($user->passkeys(), static fn (PasskeyCredential $p): bool => $p->credentialId() === $claim->credentialId);
        if (!$command instanceof PasskeyVerification || null === $credential) {
            return CodeVerificationOutcome::Rejected;
        }

        try {
            $signCount = $this->ceremony->verifyAssertion($command->code, new PasskeyOptions($command->options), $credential, $user->id()->toString());
        } catch (PasskeyRejected) {
            return CodeVerificationOutcome::Rejected;
        }

        return $user->usePasskey($credential->credentialId(), $signCount, $now);
    }
}
