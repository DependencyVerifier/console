<?php

declare(strict_types=1);

namespace Tests\Instance\Unit\Domain\Model;

use Instance\Domain\Model\ConsoleVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleVersionTest extends TestCase
{
    #[Test]
    public function it_should_read_a_strict_version_with_or_without_its_v(): void
    {
        self::assertSame('1.1.2', ConsoleVersion::tryFrom('1.1.2')?->toString());
        self::assertSame('1.1.2', ConsoleVersion::tryFrom('v1.1.2')?->toString());
        self::assertSame('10.20.300', ConsoleVersion::tryFrom('v10.20.300')?->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonVersions(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'empty' => [''];
        yield 'git describe' => ['v1.1.0-3-gabc123'];
        yield 'pre-release' => ['1.1.0-rc1'];
        yield 'two parts' => ['1.1'];
        yield 'four parts' => ['1.1.0.1'];
        yield 'prefix' => ['x1.1.0'];
        yield 'capital V' => ['V1.1.0'];
        yield 'trailing space' => ['1.1.0 '];
        yield 'leading space' => [' 1.1.0'];
        yield 'newline' => ["1.1.0\n"];
    }

    #[Test]
    #[DataProvider('provideNonVersions')]
    public function it_should_have_no_version_for_anything_else(string $raw): void
    {
        self::assertNull(ConsoleVersion::tryFrom($raw));
    }

    #[Test]
    public function it_should_compare_versions_numerically(): void
    {
        $version = static fn (string $raw): ConsoleVersion => ConsoleVersion::tryFrom($raw) ?? self::fail($raw);

        self::assertTrue($version('1.2.0')->isNewerThan($version('1.1.9')));
        self::assertTrue($version('1.10.0')->isNewerThan($version('1.9.0')), 'Numeric, not lexical.');
        self::assertTrue($version('2.0.0')->isNewerThan($version('v1.99.99')));
        self::assertFalse($version('1.1.0')->isNewerThan($version('1.1.0')), 'Equal is not newer.');
        self::assertFalse($version('v1.1.0')->isNewerThan($version('1.1.0')));
        self::assertFalse($version('1.0.9')->isNewerThan($version('1.1.0')));
    }
}
