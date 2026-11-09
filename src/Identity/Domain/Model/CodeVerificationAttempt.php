<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Technical record of a code verification, outside the User aggregate (no business invariant): written in the same
 * transaction as the counter, read back by GetCodeVerificationOutcome. Not the audit log; pruned after one hour.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_code_verification_attempt')]
#[ORM\Index(name: 'identity_code_verification_attempt_user', columns: ['user_id', 'created_at'])]
class CodeVerificationAttempt
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $userId,
        #[ORM\Column(length: 32, enumType: CodeKind::class)]
        private CodeKind $kind,
        #[ORM\Column(length: 16, enumType: CodeVerificationOutcome::class)]
        private CodeVerificationOutcome $outcome,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function outcome(): CodeVerificationOutcome
    {
        return $this->outcome;
    }

    public function userId(): UserId
    {
        return UserId::of($this->userId);
    }

    public function kind(): CodeKind
    {
        return $this->kind;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
