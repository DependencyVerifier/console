<?php

declare(strict_types=1);

namespace Tests\Instance\Unit\Domain\Model;

use Instance\Domain\Model\InvalidReleaseNotesUrl;
use Instance\Domain\Model\ReleaseNotesUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReleaseNotesUrlTest extends TestCase
{
    #[Test]
    public function it_should_keep_an_absolute_https_url(): void
    {
        $url = 'https://github.com/DependencyVerifier/console/releases/tag/v1.1.2';

        self::assertSame($url, ReleaseNotesUrl::fromString($url)->toString());
        self::assertSame('https://example.com/', ReleaseNotesUrl::fromString('https://example.com/')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidUrls(): iterable
    {
        yield 'http' => ['http://github.com/DependencyVerifier/console/releases/tag/v1.1.2'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relative' => ['/releases/tag/v1.1.2'];
        yield 'protocol-relative' => ['//github.com/x'];
        yield 'no path' => ['https://github.com'];
        yield 'credentials' => ['https://user@evil.test/x'];
        yield 'empty' => [''];
        yield 'space in host' => ['https://git hub.com/x'];
        yield 'space in path' => ['https://github.com/a b'];
        yield 'leading text' => ['see https://github.com/x'];
        yield 'trailing newline' => ["https://github.com/x\n"];
    }

    #[Test]
    #[DataProvider('provideInvalidUrls')]
    public function it_should_refuse_anything_else(string $url): void
    {
        $this->expectException(InvalidReleaseNotesUrl::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('Cannot use "%s" as release notes URL', $url));

        ReleaseNotesUrl::fromString($url);
    }
}
