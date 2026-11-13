<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Page;

use Notification\UI\Controller\RouteCollection;
use Notification\UI\DataTable\RulesTable;
use Notification\UI\DataTable\WebhooksTable;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/notifications', name: RouteCollection::TEAM_NOTIFICATIONS->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class TeamNotificationsController
{
    public function __construct(private NotificationPage $page, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        $view = $this->page->view($teamId, null);
        $tables = ['rules' => $this->tables->createFromType(RulesTable::class, ['teamId' => $teamId, 'projectId' => null])->handleRequest($request)];
        if ($view->managesChannels) {
            $tables['webhooks'] = $this->tables->createFromType(WebhooksTable::class, ['teamId' => $teamId])->handleRequest($request);
        }

        return $this->page->team($teamId, $tables);
    }
}
