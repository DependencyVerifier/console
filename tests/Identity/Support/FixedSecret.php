<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Application\Port\TotpSecretGenerator;

final class FixedSecret implements TotpSecretGenerator
{
    public function generate(): string
    {
        return 'JBSWY3DPEHPK3PXP';
    }
}
