<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\UI\Controller\RouteCollection;
use Identity\UI\DataTable\UsersTable;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page shell rendered at once; the list is a server-side DataTable (POST to this URL for each draw).
 */
#[Route(path: '/admin/users', name: RouteCollection::ADMIN_USERS->value, methods: ['GET', 'POST'])]
final class AdminUsersController extends AbstractController
{
    public function __construct(private readonly DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request): Response
    {
        $table = $this->tables->createFromType(UsersTable::class)->handleRequest($request);
        if ($table->isCallback()) {
            return $table->getResponse();
        }

        return $this->render('identity/admin/users.html.twig', ['nav_active' => 'admin', 'table' => $table]);
    }
}
