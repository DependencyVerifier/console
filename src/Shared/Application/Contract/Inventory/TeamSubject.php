<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

/**
 * An application or a host as the matching sees it.
 */
final readonly class TeamSubject
{
    public const string APPLICATION = 'application';
    public const string HOST = 'host';

    /**
     * @param list<array{purl: string, direct: bool}>    $packages
     * @param list<array{name: string, version: string}> $runtimes
     * @param ?array{id: string, version: string}        $os
     * @param string                                     $name          the application's name, the host's hostname
     * @param ?string                                    $environmentId of an application attached to one
     */
    public function __construct(public string $type, public string $id, public array $packages, public array $runtimes, public ?array $os, public string $name = '', public ?string $environmentId = null)
    {
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }
}
