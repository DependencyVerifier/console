<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Shared\Application\Contract\Organization\RecipientAdmission;

final class RecordingAdmission implements RecipientAdmission
{
    /** @var list<array{string, string, string, string}> */
    public array $admitted = [];
    public ?\Throwable $refusal = null;

    public function admitRecipient(string $teamId, string $projectId, string $userId, string $grantedBy): void
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }

        $this->admitted[] = [$teamId, $projectId, $userId, $grantedBy];
    }
}
