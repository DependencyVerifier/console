<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security\TwoFactor;

use Identity\Application\Command\SecondFactor\IssueEmailLoginCode;
use Identity\Application\Command\SecondFactor\VerifyEmailLoginCode;
use Identity\Infrastructure\Security\SecurityUser;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Email code, the second factor of every account without an active TOTP (decision 2). Hashed, 5 tries, 10 minutes;
 * issued once per login (prepared once per 2FA token), resent on demand within the limit.
 */
#[AutoconfigureTag('scheb_two_factor.provider', ['alias' => self::NAME])]
final readonly class EmailCodeProvider implements TwoFactorProviderInterface
{
    public const string NAME = 'depverify_email';

    public function __construct(
        private CodeVerifyingProvider $verifier,
        private CommandBus $commands,
        private TwoFactorFormRenderer $renderer,
    ) {
    }

    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        $user = $context->getUser();

        return $user instanceof SecurityUser && !$user->isTotpActive();
    }

    public function needsPreparation(): bool
    {
        return true;
    }

    public function prepareAuthentication(object $user): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }

        try {
            $this->commands->dispatch(new IssueEmailLoginCode($user->id, Uuid::v7()->toRfc4122()));
        } catch (AlreadyAudited) {
            // Throttled or refused: audited; the form offers to resend later.
        }
    }

    public function validateAuthenticationCode(object $user, string $authenticationCode): bool
    {
        return $user instanceof SecurityUser
            && $this->verifier->verify(new VerifyEmailLoginCode($user->id, $authenticationCode, Uuid::v7()->toRfc4122()));
    }

    public function getFormRenderer(): TwoFactorFormRendererInterface
    {
        return $this->renderer;
    }
}
