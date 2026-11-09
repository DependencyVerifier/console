<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Crypto;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Identity\Domain\Model\SecondFactor\PendingTotp;
use Identity\Domain\Model\TotpSecretBinding;
use Identity\Domain\Model\User;
use OTPHP\TOTP;
use Shared\Application\Crypto\SecretBox;

/**
 * Enrolment screen data: the otpauth:// URI rendered as an SVG QR code, generated locally (no external service),
 * and the secret in text form for manual entry. Only for a pending enrolment.
 */
final readonly class TotpProvisioning
{
    public function __construct(private SecretBox $secretBox)
    {
    }

    /**
     * @return array{secret: string, qr: string}
     */
    public function of(User $user): array
    {
        $state = $user->secondFactor()->state();
        if (!$state instanceof PendingTotp) {
            throw new \LogicException('No pending TOTP enrolment.');
        }

        $secret = $this->secretBox->decrypt($state->encryptedSecret(), TotpSecretBinding::for($user->id()));
        $label = $user->email()->value();
        if ('' === $secret || '' === $label) {
            throw new \UnexpectedValueException('Empty TOTP secret or label.');
        }

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($label);
        $totp->setIssuer('DependencyVerifier');

        $qr = new Builder(writer: new SvgWriter(), data: $totp->getProvisioningUri(), size: 240, margin: 8)->build();

        return ['secret' => $secret, 'qr' => $qr->getString()];
    }
}
