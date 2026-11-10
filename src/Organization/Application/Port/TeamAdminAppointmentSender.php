<?php

declare(strict_types=1);

namespace Organization\Application\Port;

/**
 * Queues the email telling an account it is now TEAM_ADMIN of a new team; it leaves after the response.
 */
interface TeamAdminAppointmentSender
{
    public function send(string $email, string $teamId, string $teamName): void;
}
