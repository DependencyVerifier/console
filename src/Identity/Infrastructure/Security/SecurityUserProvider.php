<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\InvalidEmailAddress;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class SecurityUserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $user = $this->users->findByEmail(EmailAddress::of($identifier));
        } catch (InvalidEmailAddress) {
            $user = null;
        }

        return null !== $user ? SecurityUser::of($user) : throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException();
        }

        $fresh = $this->users->find(UserId::fromString($user->id));

        return null !== $fresh && $fresh->canLogIn() ? SecurityUser::of($fresh) : throw new UserNotFoundException();
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }
}
