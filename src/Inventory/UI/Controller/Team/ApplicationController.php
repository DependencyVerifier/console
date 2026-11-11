<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Team;

use Inventory\Application\Query\Team\GetApplication;
use Inventory\Application\Query\Team\ListPackages;
use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\DataTable\PackagesTable;
use Omines\DataTablesBundle\DataTableFactory;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/applications/{appId}', name: RouteCollection::APPLICATION->value, requirements: ['teamId' => Requirement::UUID, 'appId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ApplicationController
{
    public function __construct(private InventoryPage $page, private QueryBus $queries, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $appId): Response
    {
        $view = $this->queries->ask(new GetApplication($appId));
        if ($view->app->teamId !== $teamId) {
            throw new NotFoundHttpException(); // the real team decides, never the URL's
        }

        return $this->page->render($teamId, 'application', 'team_applications', [
            'packages' => $this->tables->createFromType(PackagesTable::class, ['teamId' => $teamId, 'of' => ListPackages::APPLICATION, 'id' => $appId])->handleRequest($request),
        ], ['view' => $view, 'application' => $view->app]);
    }
}
