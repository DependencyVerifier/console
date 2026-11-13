<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Identity\Domain\Event\BackupCodesRegenerated;
use Identity\Domain\Event\EmailChanged;
use Identity\Domain\Event\EmailLoginCodeIssued;
use Identity\Domain\Event\PasskeyRegistered;
use Identity\Domain\Event\PasskeyRevoked;
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

use function Symfony\Component\String\u;

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

    public const int DISPLAY_NAME_MAX = 100;
    public const int PASSKEYS_MAX = 10;
    public const int PASSKEY_NAME_MAX = 64;

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

    /** Shown in the lists and the sidebar; null: the local part of the email is shown instead. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $displayName = null;

    /** @var Collection<int, BackupCode> */
    #[ORM\OneToMany(targetEntity: BackupCode::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $backupCodes;

    /** @var Collection<int, PasskeyCredential> */
    #[ORM\OneToMany(targetEntity: PasskeyCredential::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $passkeys;

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
        $this->passkeys = new ArrayCollection();
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

    /**
     * New account created by the super admin from /admin/users: no password yet. It may log in once it has chosen
     * one through its setup link (7 days), a password reset or a team invitation.
     */
    public static function invite(UserId $id, EmailAddress $email, \DateTimeImmutable $now): self
    {
        $user = new self($id->value, $email, $now);
        $user->privileges = Privileges::none()->granting(Permission::AccountLogin);

        return $user;
    }

    /**
     * A notification recipient added by email (decision 16): no password, no `account.login`. It never signs in, until
     * promoted by an invitation.
     */
    public static function notificationOnly(UserId $id, EmailAddress $email, \DateTimeImmutable $now): self
    {
        return new self($id->value, $email, $now);
    }

    public function id(): UserId
    {
        return UserId::of($this->id);
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    /**
     * The name given by the super admin, '' when none (never null, like every public method of the aggregate).
     */
    public function displayName(): string
    {
        return $this->displayName ?? '';
    }

    /**
     * Empty clears the name. At most 100 characters, surrounding spaces removed.
     */
    public function rename(string $displayName): void
    {
        $name = u($displayName)->trim();
        if ($name->length() > self::DISPLAY_NAME_MAX) {
            throw new InvalidDisplayName();
        }

        $this->displayName = $name->isEmpty() ? null : $name->toString();
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

    /**
     * Invited and still without a password (it cannot log in yet).
     */
    public function awaitsPassword(): bool
    {
        return !$this->credentials->hasPassword();
    }

    /**
     * May choose a password through an emailed link: active and allowed to log in (a notification-only account is
     * not), whether it already has a password or still awaits its first one.
     */
    public function maySetPassword(): bool
    {
        return $this->status->isActive() && $this->privileges->has(Permission::AccountLogin);
    }

    /**
     * Without `account.login` (and not a super admin): it receives notifications, never signs in.
     */
    public function isNotificationOnly(): bool
    {
        return !$this->privileges->has(Permission::AccountLogin) && !$this->isSuperAdmin();
    }

    /**
     * A notification-only account becomes a full one (an invitation accepted, or made the first admin of a team):
     * it may sign in once it chose a password through its link, keeping its memberships (decision 16).
     */
    public function promote(): void
    {
        if ($this->isNotificationOnly()) {
            $this->privileges = $this->privileges->granting(Permission::AccountLogin);
        }
    }

    public function remainingBackupCodes(): int
    {
        return $this->backupCodes->filter(static fn (BackupCode $code): bool => !$code->isUsed())->count();
    }

    /**
     * Applied once the holder of the new address confirmed it (EmailChangeRequest). Every session is revoked: the
     * identifier changed. The previous address is told.
     */
    public function changeEmail(EmailAddress $email): void
    {
        if ($this->email->equals($email)) {
            return;
        }

        $previous = $this->email->value();
        $this->email = $email;
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new EmailChanged($this->id->toRfc4122(), $previous));
    }

    // ---------------------------------------------------------------- password

    public function changePassword(string $passwordHash): void
    {
        $this->credentials = Credentials::password($passwordHash);
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new PasswordChanged($this->id->toRfc4122()));
    }

    /**
     * Password from an emailed link: the first one of an invited account (no session to revoke, no alert), else a
     * change like any other.
     */
    public function choosePassword(string $passwordHash): void
    {
        if ($this->credentials->hasPassword()) {
            $this->changePassword($passwordHash);

            return;
        }

        $this->credentials = Credentials::password($passwordHash);
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

    // ---------------------------------------------------------------- passkeys

    /**
     * @return list<PasskeyCredential> oldest first
     */
    public function passkeys(): array
    {
        return array_values($this->passkeys->toArray());
    }

    public function hasPasskey(Uuid $passkeyId): bool
    {
        return $this->passkeys->exists(static fn (int $key, PasskeyCredential $p): bool => $p->id()->equals($passkeyId));
    }

    /**
     * A passkey verified by the ceremony, under a name its holder recognizes (1 to 64 characters, unique within the
     * account, case aside). At most 10. Sessions are kept: adding a way in takes none away.
     */
    public function registerPasskey(Uuid $passkeyId, NewPasskey $passkey, string $name, \DateTimeImmutable $now): void
    {
        $name = u($name)->trim();
        $taken = $this->passkeys->exists(static fn (int $key, PasskeyCredential $p): bool => u($p->name())->lower()->equalsTo($name->lower()->toString()));
        if ($name->isEmpty() || $name->length() > self::PASSKEY_NAME_MAX || $taken) {
            throw new InvalidPasskeyName();
        }

        if ($this->passkeys->count() >= self::PASSKEYS_MAX) {
            throw new PasskeyLimitReached();
        }

        $this->passkeys->add(PasskeyCredential::of($passkeyId, $this, $passkey, $name->toString(), $now));
        $this->recordThat(new PasskeyRegistered($this->id->toRfc4122()));
    }

    /**
     * Removes a passkey (nothing when the account has none of that id): every session and trusted device is revoked.
     */
    public function revokePasskey(Uuid $passkeyId): void
    {
        $passkey = $this->passkeys->findFirst(static fn (int $key, PasskeyCredential $p): bool => $p->id()->equals($passkeyId));
        if (null === $passkey) {
            return;
        }

        $this->passkeys->removeElement($passkey);
        $this->sessionVersions = $this->sessionVersions->revokeAll();
        $this->recordThat(new PasskeyRevoked($this->id->toRfc4122()));
    }

    /**
     * A signature of one of its passkeys, verified by the ceremony: accepted when its counter grows (or stays at 0).
     *
     * @phpstan-impure
     */
    public function usePasskey(string $credentialId, int $signCount, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        $passkey = $this->passkeys->findFirst(static fn (int $key, PasskeyCredential $p): bool => $p->credentialId() === $credentialId);

        return null !== $passkey && $passkey->use($signCount, $now) ? CodeVerificationOutcome::Accepted : CodeVerificationOutcome::Rejected;
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

        if ($this->status->isDeleted()) {
            throw new AccountDeleted();
        }

        $this->status = AccountStatus::active();
        $this->recordThat(new UserReactivated($this->id->toRfc4122()));
    }

    /**
     * Soft delete: an active account is deactivated first (sessions revoked, memberships suspended through
     * UserDeactivated), a deactivated one is only hidden. Idempotent.
     */
    public function delete(\DateTimeImmutable $now): void
    {
        if ($this->status->isDeleted()) {
            return;
        }

        if ($this->status->isActive()) {
            $this->sessionVersions = $this->sessionVersions->revokeAll();
            $this->recordThat(new UserDeactivated($this->id->toRfc4122()));
        }

        $this->status = AccountStatus::deleted($now);
    }

    /**
     * Back in the list as deactivated, never active: giving the access back stays an explicit reactivation.
     */
    public function restore(\DateTimeImmutable $now): void
    {
        if ($this->status->isDeleted()) {
            $this->status = AccountStatus::disabled($now);
        }
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
