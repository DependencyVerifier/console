<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Shared\Application\Audit\Actor;
use Shared\Application\Security\CurrentActor;
use Shared\Infrastructure\Security\RuntimeCurrentActor;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The user of the security token (also during the second factor, so code verifications are attributed to them),
 * else `cli` / `anonymous`. Super admin only once fully authenticated.
 */
final readonly class AuthenticatedCurrentActor implements CurrentActor
{
    public function __construct(private TokenStorageInterface $tokens, private RuntimeCurrentActor $runtime)
    {
    }

    public function actor(): Actor
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof SecurityUser ? Actor::user(Uuid::fromString($user->id)) : $this->runtime->actor();
    }

    public function isSuperAdmin(): bool
    {
        $token = $this->tokens->getToken();
        $user = $token?->getUser();

        return !$token instanceof TwoFactorTokenInterface && $user instanceof SecurityUser && $user->superAdmin;
    }

    public function isFullyAuthenticated(): bool
    {
        $token = $this->tokens->getToken();

        return null !== $token && !$token instanceof TwoFactorTokenInterface && $token->getUser() instanceof SecurityUser;
    }
}
