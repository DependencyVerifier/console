<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Project\ListEnvironments;
use Organization\Application\Query\View\EnvironmentRow;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListEnvironmentsHandler implements QueryHandler
{
    public function __construct(private ProjectVisibility $visibility, private OrganizationReadModel $read)
    {
    }

    /**
     * @return Page<EnvironmentRow>
     */
    public function __invoke(ListEnvironments $query): Page
    {
        $this->visibility->assertVisible($query->projectId);

        return $this->read->environments($query->projectId, $query->page);
    }
}
