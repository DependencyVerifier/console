<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Contract\Handler;

use Exposure\Application\Query\FindingReadModel;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Exposure\FindingDigest;
use Shared\Application\Contract\Exposure\GetFindingDigests;
use Symfony\Component\Uid\Uuid;

final readonly class GetFindingDigestsHandler implements QueryHandler
{
    public function __construct(private FindingReadModel $read, private ClockInterface $clock)
    {
    }

    /**
     * @return list<FindingDigest> the findings of the team among the ids, in their order
     */
    public function __invoke(GetFindingDigests $query): array
    {
        $digests = [];
        foreach ($query->findingIds as $id) {
            $row = Uuid::isValid($id) ? $this->read->find($id, $this->clock->now()) : null;
            if (null !== $row && $row->teamId === $query->teamId) {
                $digests[] = new FindingDigest(
                    $row->id, $row->teamId, $row->kind, $row->title, $row->package, $row->installed, $row->fixed, $row->severity, $row->cvss, $row->knownExploited, $row->epss,
                    $row->eolLevel, $row->endOfLife, $row->suggested, $row->latest, $row->aliases, $row->status, $row->isAccepted(), $row->subjectType, $row->subjectId, $row->subjectName,
                    $row->projectId, $row->environmentId,
                );
            }
        }

        return $digests;
    }
}
