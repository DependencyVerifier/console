<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

/**
 * Route parameters of a frame (team id, project id…), taken from the route after the voters, never from the query
 * string.
 */
final readonly class FrameContext
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(public array $parameters = [])
    {
    }

    /**
     * From the `_route_params` request attribute: string parameters only.
     */
    public static function fromRouteParameters(mixed $parameters): self
    {
        $strings = [];
        foreach (\is_array($parameters) ? $parameters : [] as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $strings[$name] = $value;
            }
        }

        return new self($strings);
    }

    public function get(string $name): string
    {
        return $this->parameters[$name] ?? throw new \InvalidArgumentException(\sprintf('Frame parameter "%s" is missing.', $name));
    }
}
