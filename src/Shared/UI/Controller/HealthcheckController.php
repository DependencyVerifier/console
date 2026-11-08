<?php

declare(strict_types=1);

namespace Shared\UI\Controller;

use Shared\Application\Bus\QueryBus;
use Shared\Application\Health\Query\GetHealthReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public liveness/readiness page for uptime monitors and container healthchecks.
 * Healthy: the Romain MILLAN healthcheck page (200). Unhealthy: a JSON summary (503), without failure details.
 */
#[Route(path: '/_healthcheck', name: RouteCollection::HEALTHCHECK->value, methods: ['GET'])]
final class HealthcheckController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {
    }

    public function __invoke(): Response
    {
        $report = $this->queryBus->ask(new GetHealthReport());

        $response = $report->isHealthy()
            ? $this->render('shared/healthcheck.html.twig', ['report' => $report])
            : new JsonResponse(['status' => 'down', 'checks' => $report->summary()], Response::HTTP_SERVICE_UNAVAILABLE);

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
