<?php

declare(strict_types=1);

namespace Exposure\UI\Frame;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\GetExposureSummary;
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
     */
    public function render(FrameContext $context, ExposureScope $scope, ?string $id, ?string $route, array $parameters = []): string
    {
        return $this->twig->render('exposure/frame/summary.html.twig', [
            'summary' => $this->queries->ask(new GetExposureSummary($context->get('teamId'), $scope, $id)),
            'link' => null === $route ? null : $this->urls->generate($route, $parameters),
        ]);
    }
}
