<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Identity\Domain\Event\EmailChangeRequested;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * A new email asked by the super admin, applied only once its holder clicks the link sent to the new address
 * (hashed single-use token, 24 hours). A new request invalidates the previous ones of the account.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_email_change_request')]
#[ORM\Index(name: 'identity_email_change_request_user', columns: ['user_id'])]
class EmailChangeRequest implements RecordsEvents
{
    use EventRecording;

    public const string VALIDITY = '+24 hours';

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
        #[ORM\Column(length: 254)]
        private string $newEmail,
        #[ORM\Column(length: 128, unique: true)]
        private string $tokenHash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->expiresAt = $createdAt->modify(self::VALIDITY);
    }

    public static function issue(Uuid $id, UserId $userId, EmailAddress $newEmail, #[\SensitiveParameter] string $plainToken, string $tokenHash, \DateTimeImmutable $now): self
    {
        $request = new self($id, $userId->value, $newEmail->value(), $tokenHash, $now);
        $request->recordThat(new EmailChangeRequested($id->toRfc4122(), $userId->toString(), $newEmail->value(), $plainToken));

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

    public function newEmail(): EmailAddress
    {
        return EmailAddress::of($this->newEmail);
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt && null === $this->invalidatedAt && $now < $this->expiresAt;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsableAt($now)) {
            throw new EmailChangeLinkInvalid();
        }

        $this->consumedAt = $now;
    }

    public function invalidate(\DateTimeImmutable $now): void
    {
        $this->invalidatedAt = $now;
    }
}
