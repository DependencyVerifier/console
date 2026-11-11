<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Team;

use Fleet\Application\Query\Team\GetAgent;
use Fleet\UI\Controller\RouteCollection;
use Fleet\UI\DataTable\OrdersTable;
use Omines\DataTablesBundle\DataTableFactory;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * One agent: its identity, its collection settings, its orders; Scan now and Configure for whom holds host.order.
 */
#[Route(path: '/teams/{teamId}/agents/{agentId}', name: RouteCollection::AGENT->value, requirements: ['teamId' => Requirement::UUID, 'agentId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class AgentController
{
    public function __construct(private FleetPage $page, private QueryBus $queries, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $agentId): Response
    {
        $agent = $this->queries->ask(new GetAgent($agentId));
        if ($agent->teamId !== $teamId) {
            throw new NotFoundHttpException(); // the real team decides, never the URL's
        }

        return $this->page->render($teamId, FleetPage::AGENTS, [
            'orders' => $this->tables->createFromType(OrdersTable::class, ['agentId' => $agentId])->handleRequest($request),
        ], ['agent' => $agent], 'agent');
    }
}
