<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Identity\Domain\Security\CodeHashMatcher;
use Symfony\Component\Uid\Uuid;

/**
 * Single-use recovery code of a User (10 at most, shown once, stored hashed). Regenerating replaces the whole set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_backup_code')]
class BackupCode
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'backupCodes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 128)]
        private string $hash,
    ) {
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    /**
     * Consumes the code when it matches and is unused.
     *
     * @phpstan-impure
     */
    public function use(#[\SensitiveParameter] string $code, CodeHashMatcher $matcher, \DateTimeImmutable $now): bool
    {
        if ($this->isUsed() || !$matcher->matches($code, $this->hash)) {
            return false;
        }

        $this->usedAt = $now;

        return true;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }
}
