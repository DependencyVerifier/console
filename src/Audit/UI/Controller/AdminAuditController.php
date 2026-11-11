<?php

declare(strict_types=1);

namespace Audit\UI\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The global audit log (`instance.audit.view`, the super admin).
 */
#[Route(path: '/admin/audit', name: RouteCollection::ADMIN_AUDIT->value, methods: ['GET'])]
final readonly class AdminAuditController
{
    public function __construct(private AuditLogPage $page)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->page->render($request, null, 'audit/admin.html.twig', ['nav_active' => 'admin_audit']);
    }
}
