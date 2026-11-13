<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Application\Port\RecipientConfirmationSender;

final class RecordingConfirmations implements RecipientConfirmationSender
{
    /** @var list<array{string, string, string}> */
    public array $sent = [];

    public function send(string $email, string $teamName, string $token): void
    {
        $this->sent[] = [$email, $teamName, $token];
    }
}
