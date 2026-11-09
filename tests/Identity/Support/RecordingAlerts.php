<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Application\Port\SecurityAlert;
use Identity\Application\Port\SecurityAlertSender;
use Identity\Domain\Model\User;

final class RecordingAlerts implements SecurityAlertSender
{
    /** @var list<array{string, SecurityAlert}> */
    public array $sent = [];

    public function send(User $user, SecurityAlert $alert): void
    {
        $this->sent[] = [$user->id()->toString(), $alert];
    }
}
