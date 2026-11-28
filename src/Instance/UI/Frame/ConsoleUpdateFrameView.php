<?php

declare(strict_types=1);

namespace Instance\UI\Frame;

use Instance\Application\Query\Update\FindAvailableUpdate;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Security\CurrentActor;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameView;
use Twig\Environment;

/**
 * The update banner, or nothing (up to date, unknown release, build without a version).
 */
final readonly class ConsoleUpdateFrameView implements FrameView
{
    public function __construct(private QueryBus $queryBus, private CurrentActor $currentActor, private Environment $twig)
    {
    }

    public function frame(): Frame
    {
        return TurboFrame::ConsoleUpdate;
    }

    public function isVisible(FrameContext $context): bool
    {
        return $this->currentActor->isSuperAdmin();
    }

    public function render(FrameContext $context): string
    {
        $update = $this->queryBus->ask(new FindAvailableUpdate());

        return null === $update ? '' : $this->twig->render('instance/frame/console_update.html.twig', ['update' => $update]);
    }
}
