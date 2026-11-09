<?php

declare(strict_types=1);

namespace Shared\Application\Security;

use function Symfony\Component\String\u;

/**
 * Who or what a limiter counts. Never a raw string: the adapter hashes the key (HMAC), so no email or IP is stored
 * in clear.
 */
final readonly class ThrottleSubject
{
    private function __construct(public string $key)
    {
    }

    public static function forAccount(string $userId): self
    {
        return self::of('account', $userId);
    }

    public static function forEmail(string $normalizedEmail): self
    {
        return self::of('email', u($normalizedEmail)->lower()->toString());
    }

    public static function forIp(string $ip): self
    {
        return self::of('ip', $ip);
    }

    public static function forTeam(string $teamId): self
    {
        return self::of('team', $teamId);
    }

    public static function forInvitation(string $invitationId): self
    {
        return self::of('invitation', $invitationId);
    }

    private static function of(string $kind, string $value): self
    {
        if ('' === u($value)->trim()->toString()) {
            throw new \InvalidArgumentException(\sprintf('Empty %s throttle subject.', $kind));
        }

        return new self($kind.':'.$value);
    }
}
