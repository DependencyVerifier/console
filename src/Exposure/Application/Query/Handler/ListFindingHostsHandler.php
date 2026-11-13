<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\ListFindingHosts;
use Exposure\Application\Security\FindingAccess;
use Shared\Application\Bus\QueryHandler;

final readonly class ListFindingHostsHandler implements QueryHandler
{
    public function __construct(private FindingAccess $access, private FindingReadModel $read)
    {
    }

    /**
     * @return array<string, string>
     */
    public function __invoke(ListFindingHosts $query): array
    {
        return $this->read->hosts($this->access->filter($query->teamId, $query->scope, $query->id));
    }
}
