<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security\TwoFactor;

use Identity\Application\Command\Passkey\VerifyPasskey;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\Infrastructure\Security\SessionKeys;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * A passkey as the second factor (an account holding one): its assertion is posted as the code, checked against the
 * options of this session (single use) by VerifyPasskey.
 */
#[AutoconfigureTag('scheb_two_factor.provider', ['alias' => self::NAME])]
final readonly class PasskeyProvider implements TwoFactorProviderInterface
{
    public const string NAME = 'depverify_passkey';

    public function __construct(private CodeVerifyingProvider $verifier, private TwoFactorFormRenderer $renderer, private RequestStack $requests)
    {
    }

    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        $user = $context->getUser();

        return $user instanceof SecurityUser && $user->hasPasskeys();
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
        $session = $this->requests->getSession();
        $options = $session->get(SessionKeys::PASSKEY_OPTIONS);
        $session->remove(SessionKeys::PASSKEY_OPTIONS);

        return $user instanceof SecurityUser
            && \is_string($options)
            && $this->verifier->verify(new VerifyPasskey($user->id, $authenticationCode, Uuid::v7()->toRfc4122(), $options));
    }

    public function getFormRenderer(): TwoFactorFormRendererInterface
    {
        return $this->renderer;
    }
}
