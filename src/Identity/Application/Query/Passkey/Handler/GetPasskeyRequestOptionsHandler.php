<?php

declare(strict_types=1);

namespace Identity\Application\Query\Passkey\Handler;

use Identity\Application\Port\PasskeyCeremony;
use Identity\Application\Port\PasskeyOptions;
use Identity\Application\Query\Passkey\GetPasskeyRequestOptions;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\PasskeyCredential;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;

final readonly class GetPasskeyRequestOptionsHandler implements QueryHandler
{
    public function __construct(private AccountAccess $access, private UserRepository $users, private PasskeyCeremony $ceremony)
    {
    }

    public function __invoke(GetPasskeyRequestOptions $query): PasskeyOptions
    {
        if (null === $query->userId) {
            return $this->ceremony->requestOptions([]);
        }

        $userId = UserId::fromString($query->userId);
        $this->access->assertSelf($userId);
        $user = $this->users->find($userId) ?? throw ResourceNotFound::of('user');

        return $this->ceremony->requestOptions(array_map(static fn (PasskeyCredential $p): string => $p->credentialId(), $user->passkeys()));
    }
}
