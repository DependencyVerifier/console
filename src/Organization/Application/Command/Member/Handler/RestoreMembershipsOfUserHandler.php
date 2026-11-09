<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\RestoreMembershipsOfUser;
use Shared\Application\Bus\CommandHandler;

final readonly class RestoreMembershipsOfUserHandler implements CommandHandler
{
    public function __construct(private MembershipCascade $cascade)
    {
    }

    public function __invoke(RestoreMembershipsOfUser $command): void
    {
        $this->cascade->apply($command->userId, false);
    }
}
