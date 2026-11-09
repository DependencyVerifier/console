<?php

declare(strict_types=1);

namespace Shared\UI\Routing;

/**
 * An internal route by name and parameters (never a URL supplied by the client).
 */
final readonly class RouteReference
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(public string $name, public array $parameters = [])
    {
    }
}
