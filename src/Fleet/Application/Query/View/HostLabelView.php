<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

/**
 * The label of a host, for its edit dialog.
 */
final readonly class HostLabelView
{
    /**
     * @param list<string> $addresses
     */
    public function __construct(public string $hostId, public string $hostname, public ?string $displayName, public array $addresses)
    {
    }
}
