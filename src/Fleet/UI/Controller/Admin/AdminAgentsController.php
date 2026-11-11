<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Admin;

use Fleet\UI\Controller\RouteCollection;
use Fleet\UI\DataTable\AdminAgentsTable;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Every agent of the instance (instance.agents.view, judged by ListAllAgents).
 */
#[Route(path: '/admin/agents', name: RouteCollection::ADMIN_AGENTS->value, methods: ['GET', 'POST'])]
final readonly class AdminAgentsController
{
    public function __construct(private DataTableFactory $tables, private Environment $twig)
    {
    }

    public function __invoke(Request $request): Response
    {
        $table = $this->tables->createFromType(AdminAgentsTable::class)->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return new Response($this->twig->render('fleet/admin/agents.html.twig', ['agents' => $table, 'nav_active' => 'admin_agents']));
    }
}
