<?php

declare(strict_types=1);

namespace Instance\UI\Controller;

use Instance\Application\Query\Version\GetBuildLabel;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The running version and the update banner alone (the fallback page of the console-update frame).
 */
#[Route(path: '/admin/instance', name: RouteCollection::ABOUT->value, methods: ['GET'])]
final readonly class InstanceAboutController
{
    public function __construct(private QueryBus $queryBus, private Environment $twig)
    {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('instance/about.html.twig', [
            'build' => $this->queryBus->ask(new GetBuildLabel()),
            'nav_active' => 'admin_instance',
        ]));
    }
}
