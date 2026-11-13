<?php

declare(strict_types=1);

namespace Identity\Application\Command\Passkey\Handler;

use Identity\Application\Command\Passkey\RegisterPasskey;
use Identity\Application\Port\PasskeyCeremony;
use Identity\Application\Port\PasskeyOptions;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

final readonly class RegisterPasskeyHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access, private PasskeyCeremony $ceremony, private ClockInterface $clock)
    {
    }

    public function __invoke(RegisterPasskey $command): void
    {
        $userId = UserId::fromString($command->userId);
        $this->access->assertSelf($userId);
        $user = $this->access->lock($userId);
        $passkey = $this->ceremony->verifyRegistration($command->response, new PasskeyOptions($command->options));
        $user->registerPasskey(Uuid::fromString($command->passkeyId), $passkey, $command->name, $this->clock->now());
    }
}
