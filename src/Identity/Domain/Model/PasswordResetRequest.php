<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Identity\Domain\Event\AccountSetupRequested;
use Identity\Domain\Event\PasswordResetRequested;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Single-use reset link (hashed token, 30 minutes). Issuing a new one invalidates the previous ones.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_password_reset_request')]
#[ORM\Index(name: 'identity_password_reset_request_user', columns: ['user_id'])]
class PasswordResetRequest implements RecordsEvents
{
    use EventRecording;

    public const string VALIDITY = '+30 minutes';

    /** The setup link of an account invited from /admin/users. */
    public const string ACCOUNT_SETUP_VALIDITY = '+7 days';

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $userId,
        #[ORM\Column(length: 128, unique: true)]
        private string $tokenHash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        string $validity,
    ) {
        $this->expiresAt = $createdAt->modify($validity);
    }

    public static function issue(Uuid $id, UserId $userId, #[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): self
    {
        $request = new self($id, $userId->value, $tokenHash, $now, self::VALIDITY);
        $request->recordThat(new PasswordResetRequested($id->toRfc4122(), $userId->toString(), $plainToken));

        return $request;
    }

    /**
     * The first password of an invited account: the same single-use link, for 7 days, with its own email.
     */
    public static function issueAccountSetup(Uuid $id, UserId $userId, #[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): self
    {
        $request = new self($id, $userId->value, $tokenHash, $now, self::ACCOUNT_SETUP_VALIDITY);
        $request->recordThat(new AccountSetupRequested($id->toRfc4122(), $userId->toString(), $plainToken));

        return $request;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return UserId::of($this->userId);
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt && null === $this->invalidatedAt && $now < $this->expiresAt;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsableAt($now)) {
            throw new PasswordResetLinkInvalid();
        }

        $this->consumedAt = $now;
    }

    public function invalidate(\DateTimeImmutable $now): void
    {
        $this->invalidatedAt ??= $now;
    }
}
