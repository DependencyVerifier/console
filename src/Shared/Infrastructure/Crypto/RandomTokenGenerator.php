<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Crypto;

use Shared\Application\Crypto\TokenGenerator;

use function Symfony\Component\String\u;

final class RandomTokenGenerator implements TokenGenerator
{
    /** No 0/o, 1/l/i: a backup code must be typable from a printout. */
    private const string BACKUP_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function urlToken(): string
    {
        return u(base64_encode(random_bytes(32)))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString();
    }

    public function numericCode(): string
    {
        return \sprintf('%06d', random_int(0, 999_999));
    }

    public function backupCode(): string
    {
        $characters = '';
        for ($i = 0; $i < 10; ++$i) {
            $characters .= u(self::BACKUP_ALPHABET)->slice(random_int(0, u(self::BACKUP_ALPHABET)->length() - 1), 1)->toString();
        }

        return u($characters)->slice(0, 5)->toString().'-'.u($characters)->slice(5)->toString();
    }
}
