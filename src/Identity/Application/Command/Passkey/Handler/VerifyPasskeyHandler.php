<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey\Handler;

use Identity\Application\Command\Passkey\VerifyPasskey;
use Identity\Application\SecondFactor\PasskeyCheck;
use Identity\Application\SecondFactor\SecondFactorAttempt;
use Shared\Application\Bus\CommandHandler;

final readonly class VerifyPasskeyHandler implements CommandHandler
{
    public function __construct(private SecondFactorAttempt $attempt, private PasskeyCheck $check)
    {
    }

    public function __invoke(VerifyPasskey $command): void
    {
        $this->attempt->run($command, $this->check);
    }
}
