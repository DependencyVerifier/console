<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Crypto;

use Identity\Domain\Model\TotpSecretBinding;
use Identity\Domain\Model\UserId;
use Identity\Domain\Security\TotpVerifier;
use OTPHP\TOTP;
use Shared\Application\Crypto\SecretBox;

use function Symfony\Component\String\u;

/**
 * RFC 6238 (SHA-1, 6 digits, 30 s), one step of leeway on each side. Returns the matching time step so the
 * aggregate can refuse a replay; comparison in constant time.
 */
final readonly class OtphpTotpVerifier implements TotpVerifier
{
    public const int PERIOD = 30;
    private const int LEEWAY_STEPS = 1;

    public function __construct(private SecretBox $secretBox)
    {
    }

    public function matchingStep(string $encryptedSecret, UserId $owner, #[\SensitiveParameter] string $code, \DateTimeImmutable $now): ?int
    {
        $code = u($code)->replace(' ', '')->trim()->toString();
        if ([] === u($code)->match('/^\d{6}$/')) {
            return null;
        }

        $secret = $this->secretBox->decrypt($encryptedSecret, TotpSecretBinding::for($owner));
        if ('' === $secret) {
            throw new \UnexpectedValueException('Empty TOTP secret.');
        }

        $totp = TOTP::createFromSecret($secret);
        $current = intdiv($now->getTimestamp(), self::PERIOD);
        $matched = null;
        for ($step = $current - self::LEEWAY_STEPS; $step <= $current + self::LEEWAY_STEPS; ++$step) {
            if ($step >= 0 && hash_equals($totp->at(max(0, $step * self::PERIOD)), $code)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }
}
