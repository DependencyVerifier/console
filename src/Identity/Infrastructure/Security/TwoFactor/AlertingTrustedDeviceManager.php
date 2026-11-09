<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security\TwoFactor;

use Identity\Application\Port\SecurityAlert;
use Identity\Application\Port\SecurityAlertSender;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Identity\Infrastructure\Security\SecurityUser;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\Target;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Scheb's trusted device manager (signed `__Host-` cookie, 30 days, revoked by trustedDeviceVersion), plus the
 * audit entry and the alert email of decision 26 when a new device is trusted.
 */
final readonly class AlertingTrustedDeviceManager implements TrustedDeviceManagerInterface
{
    public function __construct(
        #[Autowire(service: 'scheb_two_factor.default_trusted_device_manager')]
        private TrustedDeviceManagerInterface $inner,
        private UserRepository $users,
        private SecurityAlertSender $alerts,
        private AuditTrail $auditTrail,
        private AuditEntryFactory $entries,
        private LoggerInterface $logger,
    ) {
    }

    public function canSetTrustedDevice(object $user, Request $request, string $firewallName): bool
    {
        return $this->inner->canSetTrustedDevice($user, $request, $firewallName);
    }

    public function addTrustedDevice(object $user, string $firewallName): void
    {
        $this->inner->addTrustedDevice($user, $firewallName);
        if (!$user instanceof SecurityUser) {
            return;
        }

        $account = $this->users->find(UserId::fromString($user->id));
        if (null !== $account) {
            $this->alerts->send($account, SecurityAlert::TrustedDeviceRegistered);
        }

        try {
            $this->auditTrail->record($this->entries->create(AuditAction::named('identity.trust_device'), Target::of('user', $user->id), Outcome::success(), AuditContext::empty()));
        } catch (\Throwable $failure) {
            $this->logger->error('The trusted device registration could not be audited.', ['exception_class' => $failure::class]);
        }
    }

    public function isTrustedDevice(object $user, string $firewallName): bool
    {
        return $this->inner->isTrustedDevice($user, $firewallName);
    }
}
