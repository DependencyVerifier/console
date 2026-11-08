<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static design previews (docs/design.md). Remove once real screens exist.
 */
final class DemoController extends AbstractController
{
    #[Route('/', name: 'app_demo_host', methods: ['GET'])]
    public function host(): Response
    {
        return $this->render('demo/host.html.twig');
    }

    #[Route('/_preview/login', name: 'app_demo_login', methods: ['GET'])]
    public function login(): Response
    {
        return $this->render('demo/login.html.twig');
    }
}
