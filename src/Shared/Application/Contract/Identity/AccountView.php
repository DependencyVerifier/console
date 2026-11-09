<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

final readonly class AccountView
{
    public function __construct(public string $userId, public string $email, public bool $active)
    {
    }
}
