<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Team;

use Inventory\Application\Query\Team\GetImage;
use Inventory\Application\Query\Team\ListPackages;
use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\DataTable\ApplicationsTable;
use Inventory\UI\DataTable\PackagesTable;
use Omines\DataTablesBundle\DataTableFactory;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/images/{imageId}', name: RouteCollection::IMAGE->value, requirements: ['teamId' => Requirement::UUID, 'imageId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ImageController
{
    public function __construct(private InventoryPage $page, private QueryBus $queries, private DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request, string $teamId, string $imageId): Response
    {
        $image = $this->queries->ask(new GetImage($teamId, $imageId));

        return $this->page->render($teamId, 'image', 'team_applications', [
            'applications' => $this->tables->createFromType(ApplicationsTable::class, ['teamId' => $teamId, 'imageId' => $image->imageId])->handleRequest($request),
            'packages' => $this->tables->createFromType(PackagesTable::class, ['teamId' => $teamId, 'of' => ListPackages::IMAGE, 'id' => $imageId])->handleRequest($request),
        ], ['image' => $image]);
    }
}
