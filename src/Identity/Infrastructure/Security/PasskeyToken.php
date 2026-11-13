<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The token of a passkey sign-in. Not in scheb's `security_tokens` (it compares exact classes): no second factor
 * after it, the passkey already verified its user (docs/cadrage.md §7).
 */
final class PasskeyToken extends PostAuthenticationToken
{
}
