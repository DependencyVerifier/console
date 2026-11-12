<?php

declare(strict_types=1);

namespace Exposure\Application\Content;

use Exposure\Domain\Repository\FindingRepository;
use Exposure\Domain\Repository\TeamExposureRepository;
use Shared\Application\Content\TeamContentEraser;
use Symfony\Component\Uid\Uuid;

/**
 * A deleted team takes its findings.
 */
final readonly class ExposureTeamContent implements TeamContentEraser
{
    public function __construct(private FindingRepository $findings, private TeamExposureRepository $exposures)
    {
    }

    public function eraseTeam(string $teamId): void
    {
        $this->findings->deleteTeam(Uuid::fromString($teamId));
        $this->exposures->deleteTeam(Uuid::fromString($teamId));
    }
}
