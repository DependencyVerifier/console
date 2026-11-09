<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Application\Port\LoginCodeSender;
use Identity\Application\Port\PasswordResetSender;
use Identity\Domain\Model\User;

final class RecordingSenders implements LoginCodeSender, PasswordResetSender
{
    /** @var list<array{string, string}> */
    public array $sent = [];

    public function send(User $user, string $code): void
    {
        $this->sent[] = [$user->id()->toString(), $code];
    }
}
