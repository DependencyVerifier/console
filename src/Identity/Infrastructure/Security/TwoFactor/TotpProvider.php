<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security\TwoFactor;

use Identity\Application\Command\SecondFactor\VerifyTotp;
use Identity\Infrastructure\Security\SecurityUser;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * TOTP through the command bus (VerifyTotp): leeway of one step, replay refused by the aggregate.
 */
#[AutoconfigureTag('scheb_two_factor.provider', ['alias' => self::NAME])]
final readonly class TotpProvider implements TwoFactorProviderInterface
{
    public const string NAME = 'depverify_totp';

    public function __construct(private CodeVerifyingProvider $verifier, private TwoFactorFormRenderer $renderer)
    {
    }

    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        $user = $context->getUser();

        return $user instanceof SecurityUser && $user->isTotpActive();
    }

    public function needsPreparation(): bool
    {
        return false;
    }

    public function prepareAuthentication(object $user): void
    {
    }

    public function validateAuthenticationCode(object $user, string $authenticationCode): bool
    {
        return $user instanceof SecurityUser
            && $this->verifier->verify(new VerifyTotp($user->id, $authenticationCode, Uuid::v7()->toRfc4122()));
    }

    public function getFormRenderer(): TwoFactorFormRendererInterface
    {
        return $this->renderer;
    }
}
