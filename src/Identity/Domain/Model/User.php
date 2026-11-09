<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Identity\Domain\Event\BackupCodesRegenerated;
use Identity\Domain\Event\EmailLoginCodeIssued;
use Identity\Domain\Event\PasswordChanged;
use Identity\Domain\Event\SecondFactorReset;
use Identity\Domain\Event\SecondFactorThrottled;
use Identity\Domain\Event\SuperAdminGranted;
use Identity\Domain\Event\SuperAdminRevoked;
use Identity\Domain\Event\TotpDisabled;
use Identity\Domain\Event\TotpEnabled;
use Identity\Domain\Event\UserDeactivated;
use Identity\Domain\Event\UserReactivated;
use Identity\Domain\Security\CodeHashMatcher;
use Identity\Domain\Security\TotpVerifier;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Account aggregate: credentials, second factor (State), session versions, status and instance privileges, plus
 * its backup codes and email login codes (changed only through its methods). No public setter, and no public method
 * returns null. Every change to credentials or second factor revokes all sessions and trusted devices.
 *
 * Deviation from the 4-attribute rule, recorded in console/CLAUDE.md: collections and the event trait.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_user')]
class User implements RecordsEvents
{
    use EventRecording;

    #[ORM\Embedded(class: Credentials::class, columnPrefix: false)]
    private Credentials $credentials;

    #[ORM\Embedded(class: SecondFactor::class, columnPrefix: false)]
    private SecondFactor $secondFactor;

    #[ORM\Embedded(class: SessionVersions::class, columnPrefix: false)]
    private SessionVersions $sessionVersions;

    #[ORM\Embedded(class: AccountStatus::class, columnPrefix: false)]
    private AccountStatus $status;

    #[ORM\Embedded(class: Privileges::class, columnPrefix: false)]
    private Privileges $privileges;

    /** @var Collection<int, BackupCode> */
    #[ORM\OneToMany(targetEntity: BackupCode::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $backupCodes;

    /** @var Collection<int, EmailLoginCode> */
    #[ORM\OneToMany(targetEntity: EmailLoginCode::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $emailLoginCodes;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Embedded(class: EmailAddress::class, columnPrefix: false)]
        private EmailAddress $email,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->credentials = Credentials::none();
        $this->secondFactor = SecondFactor::notConfigured();
        $this->sessionVersions = SessionVersions::initial();
        $this->status = AccountStatus::active();
        $this->privileges = Privileges::none();
        $this->backupCodes = new ArrayCollection();
        $this->emailLoginCodes = new ArrayCollection();
    }

    public static function createSuperAdmin(UserId $id, EmailAddress $email, string $passwordHash, \DateTimeImmutable $now): self
    {
        $user = new self($id->value, $email, $now);
        $user->credentials = Credentials::password($passwordHash);
        $user->privileges = Privileges::none()->granting(Permission::AccountLogin)->withSuperAdmin(true);

        return $user;
    }

    /**
     * New account created when an invitation is accepted: password chosen, may log in.
     */
    public static function activateFromInvitation(UserId $id, EmailAddress $email, string $passwordHash, \DateTimeImmutable $now): self
    {
        $user = new self($id->value, $email, $now);
        $user->credentials = Credentials::password($passwordHash);
        $user->privileges = Privileges::none()->granting(Permission::AccountLogin);

        return $user;
    }

    public function id(): UserId
    {
        return UserId::of($this->id);
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    public function secondFactor(): SecondFactor
    {
        return $this->secondFactor;
    }

    public function sessionVersions(): SessionVersions
    {
        return $this->sessionVersions;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function privileges(): Privileges
    {
        return $this->privileges;
    }

    public function isSuperAdmin(): bool
    {
        return $this->privileges->isSuperAdmin();
    }

    public function canLogIn(): bool
    {
        return $this->status->isActive() && $this->credentials->hasPassword() && $this->privileges->has(Permission::AccountLogin);
    }

    public function remainingBackupCodes(): int
    {
        return $this->backupCodes->filter(static fn (BackupCode $code): bool => !$code->isUsed())->count();
    }

    // ---------------------------------------------------------------- password

    public function changePassword(string $passwordHash): void
    {
        $this->credentials = Credentials::password($passwordHash);
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new PasswordChanged($this->id->toRfc4122()));
    }

    // ---------------------------------------------------------------- email code

    public function issueEmailLoginCode(Uuid $codeId, #[\SensitiveParameter] string $plainCode, string $hash, \DateTimeImmutable $now): void
    {
        $state = $this->secondFactor->state();
        if (!$state->allowsEmailCode()) {
            throw new InvalidSecondFactorTransition('issue_email_code', $state->name());
        }

        foreach ($this->emailLoginCodes as $code) {
            $code->invalidate($now);
        }

        $this->emailLoginCodes->add(new EmailLoginCode($codeId, $this, $hash, $now));
        $this->recordThat(new EmailLoginCodeIssued($this->id->toRfc4122(), $plainCode));
    }

    /**
     * @phpstan-impure
     */
    public function verifyEmailLoginCode(#[\SensitiveParameter] string $code, CodeHashMatcher $matcher, Uuid $attemptId, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        $state = $this->secondFactor->state();
        if (!$state->allowsEmailCode()) {
            throw new InvalidSecondFactorTransition('verify_email_code', $state->name());
        }

        foreach ($this->emailLoginCodes as $loginCode) {
            if ($loginCode->isActive()) {
                return $loginCode->verify($code, $matcher, $attemptId, $now);
            }
        }

        return CodeVerificationOutcome::Expired;
    }

    // ---------------------------------------------------------------- TOTP

    public function startTotpEnrollment(string $encryptedSecret): void
    {
        $this->secondFactor = $this->secondFactor->state()->startTotpEnrollment($encryptedSecret);
    }

    /**
     * Confirms a pending enrolment with a first code; on acceptance only, TOTP becomes active, the backup codes are
     * set and every session is revoked (the user logs in again with the new factor).
     *
     * @param list<string> $backupCodeHashes
     *
     * @phpstan-impure
     */
    public function confirmTotp(#[\SensitiveParameter] string $code, TotpVerifier $verifier, array $backupCodeHashes, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        $verification = $this->secondFactor->state()->confirmTotp($this->stepMatcher($code, $verifier, $now), $now);
        if (!$verification->outcome->isAccepted()) {
            return $verification->outcome;
        }

        $this->secondFactor = $verification->next;
        $this->replaceBackupCodes($backupCodeHashes);
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new TotpEnabled($this->id->toRfc4122()));

        return $verification->outcome;
    }

    /**
     * @phpstan-impure
     */
    public function verifyTotp(#[\SensitiveParameter] string $code, TotpVerifier $verifier, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        $verification = $this->secondFactor->state()->verifyTotp($this->stepMatcher($code, $verifier, $now));
        $this->secondFactor = $verification->next;

        return $verification->outcome;
    }

    public function disableTotp(): void
    {
        $wasActive = $this->secondFactor->state()->isTotpActive();
        $this->secondFactor = $this->secondFactor->state()->disableTotp();
        $this->backupCodes->clear();
        if ($wasActive) {
            $this->sessionVersions = $this->sessionVersions->revokeAll();
            $this->recordThat(new TotpDisabled($this->id->toRfc4122()));
        }
    }

    /**
     * Super admin rescue: total and idempotent in every state.
     */
    public function resetSecondFactor(): void
    {
        $this->secondFactor = $this->secondFactor->state()->reset();
        $this->backupCodes->clear();
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new SecondFactorReset($this->id->toRfc4122()));
    }

    // ---------------------------------------------------------------- backup codes

    /**
     * @phpstan-impure
     */
    public function useBackupCode(#[\SensitiveParameter] string $code, CodeHashMatcher $matcher, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        $state = $this->secondFactor->state();
        if (!$state->isTotpActive()) {
            throw new InvalidSecondFactorTransition('use_backup_code', $state->name());
        }

        foreach ($this->backupCodes as $backupCode) {
            if ($backupCode->use($code, $matcher, $now)) {
                return CodeVerificationOutcome::Accepted;
            }
        }

        return CodeVerificationOutcome::Rejected;
    }

    /**
     * @param list<string> $hashes
     */
    public function regenerateBackupCodes(array $hashes): void
    {
        $state = $this->secondFactor->state();
        if (!$state->isTotpActive()) {
            throw new InvalidSecondFactorTransition('regenerate_backup_codes', $state->name());
        }

        $this->replaceBackupCodes($hashes);
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new BackupCodesRegenerated($this->id->toRfc4122()));
    }

    /**
     * A fact without state change: the counter lives in the limiter, not in the aggregate (recorded in CLAUDE.md).
     */
    public function noteSecondFactorThrottled(\DateTimeImmutable $until): void
    {
        $this->recordThat(new SecondFactorThrottled($this->id->toRfc4122(), $until));
    }

    // ---------------------------------------------------------------- account

    public function deactivate(\DateTimeImmutable $now): void
    {
        if (!$this->status->isActive()) {
            return;
        }

        $this->status = AccountStatus::disabled($now);
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new UserDeactivated($this->id->toRfc4122()));
    }

    public function reactivate(): void
    {
        if ($this->status->isActive()) {
            return;
        }

        $this->status = AccountStatus::active();
        $this->recordThat(new UserReactivated($this->id->toRfc4122()));
    }

    public function grantSuperAdmin(): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $this->privileges = $this->privileges->withSuperAdmin(true);
        $this->recordThat(new SuperAdminGranted($this->id->toRfc4122()));
    }

    public function revokeSuperAdmin(): void
    {
        if (!$this->isSuperAdmin()) {
            return;
        }

        $this->privileges = $this->privileges->withSuperAdmin(false);
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new SuperAdminRevoked($this->id->toRfc4122()));
    }

    /**
     * @return \Closure(string): ?int
     */
    private function stepMatcher(#[\SensitiveParameter] string $code, TotpVerifier $verifier, \DateTimeImmutable $now): \Closure
    {
        $owner = $this->id();

        return static fn (string $encryptedSecret): ?int => $verifier->matchingStep($encryptedSecret, $owner, $code, $now);
    }

    /**
     * @param list<string> $hashes
     */
    private function replaceBackupCodes(array $hashes): void
    {
        $this->backupCodes->clear();
        foreach ($hashes as $hash) {
            $this->backupCodes->add(new BackupCode(Uuid::v7(), $this, $hash));
        }
    }
}
