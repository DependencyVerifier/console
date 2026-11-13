<?php

declare(strict_types=1);

namespace Exposure\UI\Frame;

use Exposure\Application\Query\GetPortfolio;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameView;
use Twig\Environment;

/**
 * On the home page: the production of every team of the actor.
 */
final readonly class PortfolioFrameView implements FrameView
{
    public function __construct(private QueryBus $queries, private Environment $twig)
    {
    }

    public function frame(): Frame
    {
        return TurboFrame::Portfolio;
    }

    public function isVisible(FrameContext $context): bool
    {
        return true;
    }

    public function render(FrameContext $context): string
    {
        return $this->twig->render('exposure/frame/portfolio.html.twig', ['portfolio' => $this->queries->ask(new GetPortfolio())]);
    }
}
