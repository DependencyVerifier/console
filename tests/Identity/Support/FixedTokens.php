<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Crypto\TokenGenerator;

final class FixedTokens implements TokenGenerator
{
    public function urlToken(): string
    {
        return 'url-token';
    }

    public function numericCode(): string
    {
        return '424242';
    }

    public function backupCode(): string
    {
        return 'aaaaa-bbbbb';
    }
}
