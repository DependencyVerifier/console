<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

/**
 * One version of a component in an environment, and where it runs.
 */
final readonly class MatrixRow
{
    public const string RUNTIME = 'runtime';
    public const string OS = 'os';
    public const string PACKAGE = 'package';

    /**
     * @param list<array{appId: string, application: string, hostId: string, host: string}> $places
     * @param int                                                                           $versions the versions of the component in the environment
     */
    public function __construct(public string $teamId, public string $kind, public string $ecosystem, public string $component, public string $version, public array $places, public int $versions)
    {
    }

    public function drifts(): bool
    {
        return $this->versions > 1;
    }
}
