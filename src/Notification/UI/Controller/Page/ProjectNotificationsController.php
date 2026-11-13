<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Page;

use Notification\UI\Controller\RouteCollection;
use Notification\UI\DataTable\RecipientsTable;
use Notification\UI\DataTable\RulesTable;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/projects/{projectId}/notifications', name: RouteCollection::PROJECT_NOTIFICATIONS->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ProjectNotificationsController
{
    public function __construct(private NotificationPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $view = $this->page->view($teamId, $projectId);
        $tables = ['rules' => $this->tables->createFromType(RulesTable::class, ['teamId' => $teamId, 'projectId' => $projectId])->handleRequest($request)];
        if ($view->manages) {
            $tables['recipients'] = $this->tables->createFromType(RecipientsTable::class, ['teamId' => $teamId, 'projectId' => $projectId])->handleRequest($request);
        }

        return $this->page->project($teamId, $projectId, $tables);
    }
}
