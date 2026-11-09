<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\RegenerateBackupCodes;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenHasher;

final readonly class RegenerateBackupCodesHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access, private TokenHasher $hasher)
    {
    }

    public function __invoke(RegenerateBackupCodes $command): void
    {
        $userId = UserId::fromString($command->userId);
        $this->access->assertSelf($userId);
        $this->access->lock($userId)->regenerateBackupCodes(array_map($this->hasher->hash(...), $command->backupCodes));
    }
}
