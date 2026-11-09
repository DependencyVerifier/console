<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Crypto;

use Identity\Application\Port\TotpSecretGenerator;
use OTPHP\TOTP;

final class OtphpTotpSecretGenerator implements TotpSecretGenerator
{
    public function generate(): string
    {
        return TOTP::generate()->getSecret();
    }
}
