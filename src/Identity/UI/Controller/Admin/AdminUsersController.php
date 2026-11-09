<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\UI\Controller\RouteCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page shell rendered at once; the list is a lazy frame (heavy zone, decision 31).
 */
#[Route(path: '/admin/users', name: RouteCollection::ADMIN_USERS->value, methods: ['GET'])]
final class AdminUsersController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('identity/admin/users.html.twig', ['nav_active' => 'admin']);
    }
}
