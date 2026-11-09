<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\SuspendMembershipsOfUser;
use Shared\Application\Bus\CommandHandler;

final readonly class SuspendMembershipsOfUserHandler implements CommandHandler
{
    public function __construct(private MembershipCascade $cascade)
    {
    }

    public function __invoke(SuspendMembershipsOfUser $command): void
    {
        $this->cascade->apply($command->userId, true);
    }
}
