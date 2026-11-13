<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey\Handler;

use Identity\Application\Command\Passkey\RevokePasskey;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Its holder, or a super admin (`instance.users.manage`); a passkey of another account is not found.
 */
final readonly class RevokePasskeyHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access)
    {
    }

    public function __invoke(RevokePasskey $command): void
    {
        $userId = UserId::fromString($command->userId);
        if (!$this->access->isActor($userId)) {
            $this->access->assertGranted(Permission::InstanceUsersManage);
        }

        $user = $this->access->lock($userId);
        $passkeyId = Uuid::fromString($command->passkeyId);
        if (!$user->hasPasskey($passkeyId)) {
            throw ResourceNotFound::of('passkey');
        }

        $user->revokePasskey($passkeyId);
    }
}
