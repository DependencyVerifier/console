<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey\Handler;

use Identity\Application\Command\Passkey\AuthenticateWithPasskey;
use Identity\Application\SecondFactor\PasskeyCheck;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Shared\Application\Bus\CommandHandler;

final readonly class AuthenticateWithPasskeyHandler implements CommandHandler
{
    public function __construct(private SecondFactorAttempt $attempt, private PasskeyCheck $check)
    {
    }

    public function __invoke(AuthenticateWithPasskey $command): void
    {
        $this->attempt->signIn($command, $this->check->forLogin());
    }
}
