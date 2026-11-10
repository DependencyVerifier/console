<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

final readonly class ProvisionedAccount
{
    public function __construct(public string $userId, public string $email, public bool $invited)
    {
    }
}
