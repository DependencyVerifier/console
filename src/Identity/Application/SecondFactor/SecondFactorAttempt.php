<?php

declare(strict_types=1);

namespace Identity\Application\SecondFactor;

use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\CodeVerificationAttempt;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\CodeVerificationAttemptRepository;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Audit\CommandAudit;
use Shared\Application\Audit\Outcome;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\Contended;
use Shared\Application\Security\ExclusiveSection;
use Shared\Application\Security\RequestContextProvider;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Symfony\Component\Uid\Uuid;

/**
 * The single algorithm of every second factor gesture, written once:
 * 0. only the account holder (the user of the 2FA token or of the session) verifies their own codes;
 * 1. User lock (FOR UPDATE, command transaction), then exclusive section on the IP key (bounded, outside it);
 * 2. peek the account (15 min, 24 h) and IP windows: refused → `Throttled`, the code is not evaluated;
 * 3. the Strategy calls the aggregate;
 * 4. on `Rejected` only, consume one token on each window (failures are counted, decision 6); the first block of
 *    the 24 h window records SecondFactorThrottled (alert email, at most one per 24 h);
 * 5. write the attempt record and exactly one audit entry (success / failure / denied).
 */
final readonly class SecondFactorAttempt
{
    private const string NO_IP = 'unknown';

    public function __construct(
        private AccountAccess $access,
        private CodeVerificationAttemptRepository $attempts,
        private AttemptThrottle $throttle,
        private ExclusiveSection $exclusiveSection,
        private RequestContextProvider $requestContext,
        private CommandAudit $audit,
        private ClockInterface $clock,
        private UserRepository $users,
    ) {
    }

    public function run(CodeVerification $command, CodeCheck $check): CodeVerificationOutcome
    {
        $userId = UserId::fromString($command->userId);
        $this->access->assertSelf($userId);

        return $this->attempt($this->access->lock($userId), $command, $check, ThrottleName::SecondFactorIp);
    }

    /**
     * The same algorithm for a sign-in without password (a passkey): no session yet, the account is the one the
     * command claims (unknown: rejected, counted on the IP), its own IP window, and an account that may not log in
     * is refused even with a valid signature.
     */
    public function signIn(CodeVerification $command, CodeCheck $check): CodeVerificationOutcome
    {
        return $this->attempt($this->users->lockById(UserId::fromString($command->userId)), $command, $check, ThrottleName::PasskeyLoginIp);
    }

    private function attempt(?User $user, CodeVerification $command, CodeCheck $check, ThrottleName $ipWindow): CodeVerificationOutcome
    {
        $userId = UserId::fromString($command->userId);
        $now = $this->clock->now();
        $account = ThrottleSubject::forAccount($command->userId);
        $ip = ThrottleSubject::forIp($this->requestContext->current()->ip ?? self::NO_IP);
        $windows = [[ThrottleName::SecondFactorShort, $account], [ThrottleName::SecondFactorLong, $account], [$ipWindow, $ip]];

        try {
            $outcome = $this->exclusiveSection->run($ip, fn (): CodeVerificationOutcome => $this->evaluate($user, $command, $check, $windows, $account, $now));
            $audited = $this->auditOutcome($outcome);
        } catch (Contended) {
            $outcome = CodeVerificationOutcome::Throttled;
            $audited = Outcome::denied('lock_contention');
        }

        $this->attempts->add(new CodeVerificationAttempt(Uuid::fromString($command->attemptId), $userId->value, $check->kind(), $outcome, $now));
        $this->attempts->pruneOlderThan($userId, $now->modify('-1 hour'));
        $this->audit->record($command, $audited);

        return $outcome;
    }

    /**
     * @param list<array{ThrottleName, ThrottleSubject}> $windows
     */
    private function evaluate(?User $user, CodeVerification $command, CodeCheck $check, array $windows, ThrottleSubject $account, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        foreach ($windows as [$name, $subject]) {
            if (!$this->throttle->peek($name, $subject)->accepted) {
                return CodeVerificationOutcome::Throttled;
            }
        }

        $outcome = null === $user ? CodeVerificationOutcome::Rejected : $check->verify($user, $command, $now);
        if (null !== $user && $outcome->isAccepted() && !$user->canLogIn()) {
            $outcome = CodeVerificationOutcome::Rejected;
        }

        if (CodeVerificationOutcome::Rejected !== $outcome) {
            return $outcome;
        }

        foreach ($windows as [$name, $subject]) {
            $decision = $this->throttle->consume($name, $subject);
            if (null !== $user && ThrottleName::SecondFactorLong === $name && $decision->justExhausted && $this->throttle->consume(ThrottleName::SecondFactorAlert, $account)->accepted) {
                $user->noteSecondFactorThrottled($decision->retryAfter);
            }
        }

        return $outcome;
    }

    private function auditOutcome(CodeVerificationOutcome $outcome): Outcome
    {
        return match ($outcome) {
            CodeVerificationOutcome::Accepted => Outcome::success(),
            CodeVerificationOutcome::Throttled => Outcome::denied('throttled'),
            default => Outcome::failure($outcome->value),
        };
    }
}
