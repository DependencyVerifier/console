<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * A passkey sign-in AuthenticateWithPasskey refused: already audited by the command (`identity.login_passkey`),
 * never written again as a login failure.
 */
final class PasskeyNotAccepted extends BadCredentialsException
{
}
