<?php

declare(strict_types=1);

namespace Shared\UI\Routing;

/**
 * Implemented by each context's `UI\Controller\RouteCollection` enum.
 * Cases hold the route name without prefix (used in `#[Route(name: ...)]`, the prefix comes from
 * `config/routes.yaml`); `prefixed()` returns the full name for `path()` / `redirectToRoute()`.
 */
interface RouteCollectionInterface
{
    public function prefixed(): string;
}
