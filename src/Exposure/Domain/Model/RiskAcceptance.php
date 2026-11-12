<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Exposure\Domain\Event\RiskAcceptanceRevoked;
use Exposure\Domain\Event\RiskAccepted;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Someone accepted the risk of a finding, for a reason and until a date: it is silenced (no notification) while the
 * acceptance runs, and counts as open again once it expires or is revoked. Kept as history, never changed but revoked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'exposure_risk_acceptance')]
#[ORM\Index(name: 'exposure_risk_acceptance_finding', columns: ['finding_id'])]
class RiskAcceptance implements RecordsEvents
{
    use EventRecording;

    public const int MAX_JUSTIFICATION = 2000;
    public const int MAX_DAYS = 365;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $findingId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'text')]
        private string $justification,
        #[ORM\Column(type: 'uuid')]
        private Uuid $acceptedBy,
        #[ORM\Column]
        private \DateTimeImmutable $acceptedAt,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $expiresOn,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $revokedAt,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $revokedBy,
    ) {
    }

    public static function accept(Uuid $id, Finding $finding, string $justification, \DateTimeImmutable $expiresOn, Uuid $by, \DateTimeImmutable $now): self
    {
        $justification = u($justification)->trim();
        if ($justification->isEmpty() || $justification->length() > self::MAX_JUSTIFICATION) {
            throw new InvalidRiskAcceptance('A justification of 1 to 2000 characters.');
        }

        $today = new \DateTimeImmutable($now->format('Y-m-d'));
        $expiry = new \DateTimeImmutable($expiresOn->format('Y-m-d'));
        if ($expiry <= $today || $expiry > $today->modify(\sprintf('+%d days', self::MAX_DAYS))) {
            throw new InvalidRiskAcceptance('An expiry after today and within a year.');
        }

        $acceptance = new self($id, $finding->id(), $finding->teamId(), $justification->toString(), $by, $now, $expiry, null, null);
        $acceptance->recordThat(new RiskAccepted($id->toRfc4122(), $finding->id()->toRfc4122(), $finding->teamId()->toRfc4122(), $expiry->format('Y-m-d')));

        return $acceptance;
    }

    public function revoke(Uuid $by, \DateTimeImmutable $now): void
    {
        if (null === $this->revokedAt) {
            $this->revokedAt = $now;
            $this->revokedBy = $by;
            $this->recordThat(new RiskAcceptanceRevoked($this->id->toRfc4122(), $this->findingId->toRfc4122(), $this->teamId->toRfc4122()));
        }
    }

    /**
     * Not revoked, and its expiry day not reached.
     */
    public function isActiveOn(\DateTimeImmutable $today): bool
    {
        return null === $this->revokedAt && new \DateTimeImmutable($today->format('Y-m-d')) < $this->expiresOn;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function findingId(): Uuid
    {
        return $this->findingId;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function justification(): string
    {
        return $this->justification;
    }

    public function acceptedBy(): Uuid
    {
        return $this->acceptedBy;
    }

    public function acceptedAt(): \DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function expiresOn(): \DateTimeImmutable
    {
        return $this->expiresOn;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedBy(): ?Uuid
    {
        return $this->revokedBy;
    }
}
