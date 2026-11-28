<?php

declare(strict_types=1);

namespace Instance\UI\Twig;

use Instance\Application\Query\Version\GetBuildLabel;
use Shared\Application\Bus\QueryBus;
use Twig\Attribute\AsTwigFunction;

/**
 * The label of the running build, for the footer of the authenticated layout only (never the public pages).
 */
final readonly class BuildLabelExtension
{
    public function __construct(private QueryBus $queryBus)
    {
    }

    #[AsTwigFunction('build_label')]
    public function buildLabel(): string
    {
        return $this->queryBus->ask(new GetBuildLabel())->label();
    }
}
