<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Shared\Application\Contract\Organization\RecipientAdmission;

final class RecordingAdmission implements RecipientAdmission
{
    /** @var list<array{string, string, string}> */
    public array $admitted = [];

    public function admitRecipient(string $teamId, string $projectId, string $userId): void
    {
        $this->admitted[] = [$teamId, $projectId, $userId];
    }
}
