<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project\Handler;

use Organization\Application\Query\MemberEmails;
use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Project\ListProjectMembers;
use Organization\Application\Query\View\MemberRow;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

final readonly class ListProjectMembersHandler implements QueryHandler
{
    public function __construct(private ProjectVisibility $visibility, private OrganizationReadModel $read, private MemberEmails $emails)
    {
    }

    /**
     * @return Page<MemberRow>
     */
    public function __invoke(ListProjectMembers $query): Page
    {
        $this->visibility->assertVisible($query->projectId);

        return $this->emails->fill($this->read->projectMembers($query->projectId, $query->page, $this->emails->matching($query->page)));
    }
}
