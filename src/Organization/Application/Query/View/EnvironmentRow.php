<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

final readonly class EnvironmentRow
{
    public function __construct(public string $id, public string $name, public bool $production)
    {
    }
}
