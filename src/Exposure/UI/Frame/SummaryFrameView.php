<?php

declare(strict_types=1);

namespace Exposure\UI\Frame;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\GetExposureSummary;
use Exposure\Application\Query\GetProductionExposure;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Frame\FrameContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * The recipe shared by the three summary frames: GetExposureSummary judges what the actor sees (404 rendered in the
 * frame), the template shows the figures and links to the Findings section.
 */
final readonly class SummaryFrameView
{
    public function __construct(private QueryBus $queries, private Environment $twig, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param array<string, string> $parameters of the route
     * @param bool                  $production show the production first (the team overview)
     */
    public function render(FrameContext $context, ExposureScope $scope, ?string $id, ?string $route, array $parameters = [], bool $production = false): string
    {
        return $this->twig->render('exposure/frame/summary.html.twig', [
            'summary' => $this->queries->ask(new GetExposureSummary($context->get('teamId'), $scope, $id)),
            'production' => $production ? $this->queries->ask(new GetProductionExposure($context->get('teamId'), $scope, $id)) : null,
            'link' => null === $route ? null : $this->urls->generate($route, $parameters),
        ]);
    }
}
