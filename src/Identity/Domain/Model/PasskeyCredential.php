<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A passkey of a User (docs/cadrage.md §5, PasskeyCredential): a WebAuthn credential (id, public key, signature
 * counter, transports, AAGUID) and the name its holder gave it. Changed only through its User.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_passkey')]
#[ORM\UniqueConstraint(name: 'identity_passkey_credential', columns: ['credential_id'])]
class PasskeyCredential
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * @param list<string> $transports
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'passkeys')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: 'text')]
        private string $credentialId,
        #[ORM\Column(type: 'text')]
        private string $publicKey,
        #[ORM\Column(type: 'bigint')]
        private int $signCount,
        #[ORM\Column(type: 'json')]
        private array $transports,
        #[ORM\Column(length: 36)]
        private string $aaguid,
        #[ORM\Column(length: 64)]
        private string $name,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function of(Uuid $id, User $user, NewPasskey $passkey, string $name, \DateTimeImmutable $now): self
    {
        return new self($id, $user, $passkey->credentialId, $passkey->publicKey, $passkey->signCount, $passkey->transports, $passkey->aaguid, $name, $now);
    }

    /**
     * A signature with the counter the authenticator now reports. A counter that does not grow (both not zero: many
     * authenticators always report 0) means a cloned authenticator: refused, nothing changes.
     *
     * @phpstan-impure
     */
    public function use(int $signCount, \DateTimeImmutable $now): bool
    {
        if (($signCount > 0 || $this->signCount > 0) && $signCount <= $this->signCount) {
            return false;
        }

        $this->signCount = $signCount;
        $this->lastUsedAt = $now;

        return true;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function signCount(): int
    {
        return $this->signCount;
    }

    /**
     * @return list<string>
     */
    public function transports(): array
    {
        return $this->transports;
    }

    public function aaguid(): string
    {
        return $this->aaguid;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
}
