<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

final readonly class RecipientAccount
{
    /**
     * @param bool $signsIn a full account (never attached by a manager: it is invited, or subscribes itself)
     */
    public function __construct(public string $userId, public bool $signsIn)
    {
    }
}
