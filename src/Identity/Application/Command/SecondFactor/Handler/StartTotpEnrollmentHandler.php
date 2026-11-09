<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\StartTotpEnrollment;
use Identity\Application\Port\TotpSecretGenerator;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\TotpSecretBinding;
use Identity\Domain\Model\UserId;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\SecretBox;

final readonly class StartTotpEnrollmentHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access, private TotpSecretGenerator $secrets, private SecretBox $secretBox)
    {
    }

    public function __invoke(StartTotpEnrollment $command): void
    {
        $userId = UserId::fromString($command->userId);
        $this->access->assertSelf($userId);
        $user = $this->access->lock($userId);
        $user->startTotpEnrollment($this->secretBox->encrypt($this->secrets->generate(), TotpSecretBinding::for($userId)));
    }
}
