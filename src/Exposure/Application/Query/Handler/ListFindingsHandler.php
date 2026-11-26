<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\ListFindings;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Application\Security\FindingAccess;
use Exposure\Domain\Model\SubjectType;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;
use Symfony\Component\Uid\Uuid;

final readonly class ListFindingsHandler implements QueryHandler
{
    public function __construct(private FindingAccess $access, private FindingReadModel $read, private ClockInterface $clock, private FindingPlaces $places)
    {
    }

    /**
     * @return Page<FindingRow>
     */
    public function __invoke(ListFindings $query): Page
    {
        $type = SubjectType::tryFrom($query->subjectType) ?? throw ResourceNotFound::of('finding');
        if (!Uuid::isValid($query->subjectId)) {
            throw ResourceNotFound::of('finding');
        }

        $this->access->project($query->teamId, $type, $query->subjectId);

        return $this->places->page($query->teamId, $this->read->ofSubject($query->teamId, $type->value, $query->subjectId, $this->clock->now(), withFixed: $query->withFixed, page: $query->page));
    }
}
