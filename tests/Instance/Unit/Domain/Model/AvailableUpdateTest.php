<?php

declare(strict_types=1);

namespace Tests\Instance\Unit\Domain\Model;

use Instance\Domain\Model\AvailableUpdate;
use Instance\Domain\Model\ConsoleVersion;
use Instance\Domain\Model\PublishedRelease;
use Instance\Domain\Model\ReleaseNotesUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AvailableUpdateTest extends TestCase
{
    private const string NOTES = 'https://github.com/DependencyVerifier/console/releases/tag/v1.2.0';

    #[Test]
    public function it_should_exist_for_a_strictly_newer_release(): void
    {
        $update = AvailableUpdate::between(self::version('1.1.2'), self::release('1.2.0'));

        self::assertNotNull($update);
        self::assertSame('1.1.2', $update->currentVersion());
        self::assertSame('1.2.0', $update->latestVersion());
        self::assertSame(self::NOTES, $update->notesUrl());
    }

    #[Test]
    public function it_should_never_exist_for_an_equal_or_older_release(): void
    {
        self::assertNull(AvailableUpdate::between(self::version('1.2.0'), self::release('1.2.0')));
        self::assertNull(AvailableUpdate::between(self::version('1.3.0'), self::release('1.2.0')));
    }

    private static function version(string $raw): ConsoleVersion
    {
        return ConsoleVersion::tryFrom($raw) ?? self::fail($raw);
    }

    private static function release(string $raw): PublishedRelease
    {
        return new PublishedRelease(self::version($raw), ReleaseNotesUrl::fromString(self::NOTES));
    }
}
