<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Identity\Domain\Security\CodeHashMatcher;
use Symfony\Component\Uid\Uuid;

/**
 * Email login code of a User (6 digits, hashed, 10 minutes, 5 tries). One active code at a time, guaranteed by the
 * aggregate and by a partial unique index on (user_id) where consumed_at and invalidated_at are null.
 * `verify()` changes state (tries counter) and returns an outcome: this deviation from command/query separation
 * is deliberate, the counter must be committed even for a wrong code.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_email_login_code')]
class EmailLoginCode
{
    public const int MAX_ATTEMPTS = 5;
    public const string VALIDITY = '+10 minutes';

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $consumedByAttemptId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'emailLoginCodes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 128)]
        private string $hash,
        #[ORM\Column]
        private \DateTimeImmutable $issuedAt,
    ) {
        $this->expiresAt = $issuedAt->modify(self::VALIDITY);
    }

    public function isActive(): bool
    {
        return null === $this->consumedAt && null === $this->invalidatedAt;
    }

    public function invalidate(\DateTimeImmutable $now): void
    {
        if ($this->isActive()) {
            $this->invalidatedAt = $now;
        }
    }

    /**
     * @phpstan-impure
     */
    public function verify(#[\SensitiveParameter] string $code, CodeHashMatcher $matcher, Uuid $attemptId, \DateTimeImmutable $now): CodeVerificationOutcome
    {
        if (!$this->isActive() || $now >= $this->expiresAt) {
            $this->invalidate($now);

            return CodeVerificationOutcome::Expired;
        }

        if ($this->attempts >= self::MAX_ATTEMPTS) {
            return CodeVerificationOutcome::Exhausted;
        }

        ++$this->attempts;
        if (!$matcher->matches($code, $this->hash)) {
            return CodeVerificationOutcome::Rejected;
        }

        $this->consumedAt = $now;
        $this->consumedByAttemptId = $attemptId;

        return CodeVerificationOutcome::Accepted;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function issuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function consumedBy(): Uuid
    {
        return $this->consumedByAttemptId ?? throw new \LogicException('This code was not consumed.');
    }
}
