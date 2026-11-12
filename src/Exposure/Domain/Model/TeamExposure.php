<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Exposure\Domain\Event\ExposureRefreshRequested;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Whether the findings of a team are to be computed again: a burst of changes (hundreds of advisories in one sync)
 * asks once, the computation clears the request when it starts (a change during it asks again).
 */
#[ORM\Entity]
#[ORM\Table(name: 'exposure_team')]
class TeamExposure implements RecordsEvents
{
    use EventRecording;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column]
        private bool $refreshRequested,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $refreshedAt,
    ) {
    }

    public static function of(Uuid $teamId): self
    {
        return new self($teamId, false, null);
    }

    public function requestRefresh(): void
    {
        if (!$this->refreshRequested) {
            $this->refreshRequested = true;
            $this->recordThat(new ExposureRefreshRequested($this->teamId->toRfc4122()));
        }
    }

    public function refreshing(\DateTimeImmutable $now): void
    {
        $this->refreshRequested = false;
        $this->refreshedAt = $now;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function isRefreshRequested(): bool
    {
        return $this->refreshRequested;
    }

    public function refreshedAt(): ?\DateTimeImmutable
    {
        return $this->refreshedAt;
    }
}
