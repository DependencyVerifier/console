<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\UI\Controller\RouteCollection;
use Identity\UI\DataTable\UsersTable;
use Omines\DataTablesBundle\DataTableFactory;
use Organization\UI\DataTable\AdminInvitationsTable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page shell rendered at once; the accounts, then the pending invitations of the instance, are server-side DataTables
 * (POST to this URL for each draw).
 */
#[Route(path: '/admin/users', name: RouteCollection::ADMIN_USERS->value, methods: ['GET', 'POST'])]
final class AdminUsersController extends AbstractController
{
    public function __construct(private readonly DataTableFactory $tables)
    {
    }

    public function __invoke(Request $request): Response
    {
        $includeDeleted = $request->query->getBoolean('deleted');
        $table = $this->tables->createFromType(UsersTable::class, ['includeDeleted' => $includeDeleted])->handleRequest($request);
        // The invitations sent to emails without an account yet live in Organization: listed under the accounts.
        $invitations = $this->tables->createFromType(AdminInvitationsTable::class)->handleRequest($request);
        foreach ([$table, $invitations] as $list) {
            if ($list->isCallback()) {
                return $list->getResponse();
            }
        }

        return $this->render('identity/admin/users.html.twig', ['nav_active' => 'admin_users', 'table' => $table, 'invitations' => $invitations, 'include_deleted' => $includeDeleted]);
    }
}
