<?php

declare(strict_types=1);

namespace Shared\Application\Crypto;

/**
 * One format per use: 256-bit URL tokens for links, 6 digits for email codes, readable `xxxxx-xxxxx` backup codes.
 */
interface TokenGenerator
{
    public function urlToken(): string;

    public function numericCode(): string;

    public function backupCode(): string;
}
