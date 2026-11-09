<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * An unknown email or a refused account fails before any hashing: verify a dummy Argon2id hash so the response time matches a wrong
 * password on an existing account. The memoized dummy hash is the same for every request of the worker (not request
 * state), so no reset is needed.
 */
#[AsEventListener]
final class EqualTimeLoginFailure
{
    private ?string $dummyHash = null;

    public function __construct(private readonly PasswordHasherFactoryInterface $hashers)
    {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        for ($exception = $event->getException(); null !== $exception; $exception = $exception->getPrevious()) {
            if ($exception instanceof UserNotFoundException || $exception instanceof AccountStatusException) {
                $hasher = $this->hashers->getPasswordHasher(SecurityUser::class);
                $this->dummyHash ??= $hasher->hash('depverify-dummy-password');
                $hasher->verify($this->dummyHash, 'depverify-wrong-password');

                return;
            }
        }
    }
}
