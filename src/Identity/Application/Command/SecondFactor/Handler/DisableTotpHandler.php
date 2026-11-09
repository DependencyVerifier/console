<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\DisableTotp;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Shared\Application\Bus\CommandHandler;

final readonly class DisableTotpHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access)
    {
    }

    public function __invoke(DisableTotp $command): void
    {
        $userId = UserId::fromString($command->userId);
        $this->access->assertSelf($userId);
        $this->access->lock($userId)->disableTotp();
    }
}
