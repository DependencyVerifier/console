<?php

declare(strict_types=1);

namespace Shared\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Landing page of the authenticated area: the production of every team of the actor (a lazy frame of Exposure).
 */
#[Route(path: '/', name: RouteCollection::HOME->value, methods: ['GET'])]
final class HomeController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('shared/home.html.twig', ['nav_active' => 'overview']);
    }
}
