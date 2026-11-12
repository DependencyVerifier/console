<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\ListTeamFindings;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Application\Security\FindingAccess;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListTeamFindingsHandler implements QueryHandler
{
    public function __construct(private FindingAccess $access, private FindingReadModel $read, private ClockInterface $clock)
    {
    }

    /**
     * @return Page<FindingRow>
     */
    public function __invoke(ListTeamFindings $query): Page
    {
        return $this->read->list($this->access->filter($query->teamId, $query->scope, $query->id), $query->criteria, $this->clock->now(), $query->page);
    }
}
