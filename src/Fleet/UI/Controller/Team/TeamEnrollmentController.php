<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Team;

use Fleet\UI\Controller\RouteCollection;
use Fleet\UI\DataTable\TokensTable;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/enrollment', name: RouteCollection::TEAM_ENROLLMENT->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class TeamEnrollmentController
{
    public function __construct(private FleetPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->page->render($teamId, FleetPage::ENROLLMENT, [
            'tokens' => $this->tables->createFromType(TokensTable::class, ['teamId' => $teamId])->handleRequest($request),
        ]);
    }
}
