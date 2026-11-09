<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Identity\Domain\Model\SecondFactor\ActiveTotp;
use Identity\Domain\Model\SecondFactor\NotConfigured;
use Identity\Domain\Model\SecondFactor\PendingTotp;
use Identity\Domain\Model\SecondFactor\SecondFactorState;

/**
 * Persisted form of the second factor state (an embeddable has a single shape): a `kind` column plus the fields of
 * the states, nullable in the database only. `state()` rebuilds the state object and checks that the fields
 * required by its kind are present.
 */
#[ORM\Embeddable]
final class SecondFactor
{
    private const string NOT_CONFIGURED = 'not_configured';
    private const string PENDING_TOTP = 'pending_totp';
    private const string ACTIVE_TOTP = 'active_totp';

    private function __construct(
        #[ORM\Column(name: 'second_factor_kind', length: 16)]
        private string $kind,
        #[ORM\Column(name: 'totp_secret', type: 'text', nullable: true)]
        private ?string $encryptedSecret,
        #[ORM\Column(name: 'totp_since', nullable: true)]
        private ?\DateTimeImmutable $since,
        #[ORM\Column(name: 'totp_last_step', type: 'bigint', nullable: true)]
        private ?int $lastAcceptedStep,
    ) {
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, null, null, null);
    }

    public static function pendingTotp(string $encryptedSecret): self
    {
        return new self(self::PENDING_TOTP, $encryptedSecret, null, null);
    }

    public static function activeTotp(string $encryptedSecret, \DateTimeImmutable $since, int $lastAcceptedStep): self
    {
        return new self(self::ACTIVE_TOTP, $encryptedSecret, $since, $lastAcceptedStep);
    }

    public function state(): SecondFactorState
    {
        return match ($this->kind) {
            self::NOT_CONFIGURED => new NotConfigured(),
            self::PENDING_TOTP => new PendingTotp($this->required($this->encryptedSecret)),
            self::ACTIVE_TOTP => new ActiveTotp(
                $this->required($this->encryptedSecret),
                $this->required($this->since),
                (int) $this->required($this->lastAcceptedStep),
            ),
            default => throw new \UnexpectedValueException(\sprintf('Unknown second factor kind "%s".', $this->kind)),
        };
    }

    /**
     * @template T
     *
     * @param T|null $value
     *
     * @return T
     */
    private function required(mixed $value): mixed
    {
        return $value ?? throw new \UnexpectedValueException(\sprintf('Second factor "%s" is missing a required field.', $this->kind));
    }
}
