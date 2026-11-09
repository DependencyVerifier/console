<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Shared\UI\Controller\RouteCollection as SharedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Sign-in form. The POST is handled by the firewall (form_login, CSRF token id `authenticate`).
 */
#[Route(path: '/login', name: RouteCollection::LOGIN->value, methods: ['GET', 'POST'])]
final class LoginController extends AbstractController
{
    public function __invoke(Request $request, AuthenticationUtils $authentication): Response
    {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute(SharedRoutes::HOME->prefixed());
        }

        $session = $request->getSession();
        $error = $session->get(SessionKeys::AUTH_ERROR);
        $session->remove(SessionKeys::AUTH_ERROR);

        $response = $this->render('identity/security/login.html.twig', [
            'last_username' => $authentication->getLastUsername(),
            'error_key' => \is_string($error) ? $error : null,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
