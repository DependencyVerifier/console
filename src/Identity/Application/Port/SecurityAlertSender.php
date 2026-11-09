<?php

declare(strict_types=1);

namespace Identity\Application\Port;

use Identity\Domain\Model\User;

interface SecurityAlertSender
{
    public function send(User $user, SecurityAlert $alert): void;
}
