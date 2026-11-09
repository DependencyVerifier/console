<?php

declare(strict_types=1);

namespace Identity\Application\EventHandler;

use Identity\Application\Port\SecurityAlert;
use Identity\Application\Port\SecurityAlertSender;
use Identity\Domain\Event\BackupCodesRegenerated;
use Identity\Domain\Event\PasswordChanged;
use Identity\Domain\Event\SecondFactorReset;
use Identity\Domain\Event\SecondFactorThrottled;
use Identity\Domain\Event\SuperAdminGranted;
use Identity\Domain\Event\SuperAdminRevoked;
use Identity\Domain\Event\TotpDisabled;
use Identity\Domain\Event\TotpEnabled;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Bus\EventHandler;

/**
 * Decision 26: one alert email to the account holder per sensitive change.
 */
final readonly class SendSecurityAlert implements EventHandler
{
    public function __construct(private UserRepository $users, private SecurityAlertSender $sender)
    {
    }

    public function __invoke(PasswordChanged|TotpEnabled|TotpDisabled|BackupCodesRegenerated|SecondFactorReset|SuperAdminGranted|SuperAdminRevoked|SecondFactorThrottled $event): void
    {
        $user = $this->users->find(UserId::fromString($event->userId));
        if (null === $user) {
            return;
        }

        $this->sender->send($user, match ($event::class) {
            PasswordChanged::class => SecurityAlert::PasswordChanged,
            TotpEnabled::class => SecurityAlert::TotpEnabled,
            TotpDisabled::class => SecurityAlert::TotpDisabled,
            BackupCodesRegenerated::class => SecurityAlert::BackupCodesRegenerated,
            SecondFactorReset::class => SecurityAlert::SecondFactorReset,
            SuperAdminGranted::class => SecurityAlert::SuperAdminGranted,
            SuperAdminRevoked::class => SecurityAlert::SuperAdminRevoked,
            SecondFactorThrottled::class => SecurityAlert::SecondFactorThrottled,
        });
    }
}
