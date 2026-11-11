<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use function Symfony\Component\String\u;

/**
 * The protocol v1 test vectors (tests/Fleet/Fixtures/protocol, copies of .github/docs/protocol/test-vectors), read
 * by dotted path (`request.headers.Signature`).
 */
final class ProtocolVectors
{
    /**
     * @return array<string, mixed>
     */
    public static function request(): array
    {
        return self::read('request-signature.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(): array
    {
        return self::read('order-signature.json');
    }

    /**
     * @param array<string, mixed> $vector
     */
    public static function string(array $vector, string $path): string
    {
        $value = self::at($vector, $path);
        \assert(\is_string($value), $path);

        return $value;
    }

    /**
     * @param array<string, mixed> $vector
     *
     * @return array<string, mixed>
     */
    public static function map(array $vector, string $path): array
    {
        $value = self::at($vector, $path);
        \assert(\is_array($value), $path);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $vector
     */
    private static function at(array $vector, string $path): mixed
    {
        $value = $vector;
        foreach (u($path)->split('.') as $key) {
            \assert(\is_array($value) && \array_key_exists($key->toString(), $value), $path);
            $value = $value[$key->toString()];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $name): array
    {
        $vector = json_decode((string) file_get_contents(\dirname(__DIR__).'/Fixtures/protocol/'.$name), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($vector));

        /** @var array<string, mixed> $vector */
        return $vector;
    }
}
