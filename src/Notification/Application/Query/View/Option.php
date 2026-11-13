<?php

declare(strict_types=1);

namespace Notification\Application\Query\View;

final readonly class Option
{
    public function __construct(public string $id, public string $label)
    {
    }
}
