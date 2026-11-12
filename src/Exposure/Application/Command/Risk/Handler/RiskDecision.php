<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Risk\Handler;

use Exposure\Application\Security\FindingAccess;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Repository\FindingRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Symfony\Component\Uid\Uuid;

/**
 * The finding the actor sees (404) and may decide on (403), for both gestures.
 */
final readonly class RiskDecision
{
    public function __construct(private FindingRepository $findings, private FindingAccess $access)
    {
    }

    public function finding(string $findingId): Finding
    {
        $finding = (Uuid::isValid($findingId) ? $this->findings->find(Uuid::fromString($findingId)) : null) ?? throw ResourceNotFound::of('finding');
        $team = $finding->teamId()->toRfc4122();
        $this->access->assertMayAccept($team, $this->access->project($team, $finding->subjectType(), $finding->subjectId()->toRfc4122()));

        return $finding;
    }
}
