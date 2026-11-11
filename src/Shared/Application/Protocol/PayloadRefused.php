<?php

declare(strict_types=1);

namespace Shared\Application\Protocol;

use Shared\Domain\BusinessRuleViolated;

/**
 * A request body of the agent protocol that cannot be read (protocol §9): `invalid_payload`, or
 * `unsupported_protocol_version`. The message is the detail sent back in the problem document.
 */
final class PayloadRefused extends BusinessRuleViolated
{
    public static function invalid(string $detail): self
    {
        return new self('invalid_payload', $detail);
    }

    public static function unsupportedVersion(): self
    {
        return new self('unsupported_protocol_version', 'This console speaks protocol version 1.');
    }
}
