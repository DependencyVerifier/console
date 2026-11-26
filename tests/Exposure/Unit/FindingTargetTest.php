<?php

declare(strict_types=1);

namespace Tests\Exposure\Unit;

use Exposure\Application\Query\View\FindingRow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a finding tells to go from the installed version: the fix of a vulnerability, the latest release of an
 * outdated dependency, the newest supported cycle of an end of life (never the last patch of the cycle that ends).
 */
final class FindingTargetTest extends TestCase
{
    #[Test]
    public function it_should_aim_at_what_fixes_each_kind_of_finding(): void
    {
        self::assertSame('4.17.21', self::row('vulnerability', fixed: '4.17.21', latest: '4.18.1', suggested: 'x')->target(), 'The fixed version.');
        self::assertNull(self::row('vulnerability', fixed: null, latest: '4.18.1')->target(), 'No fix yet.');
        self::assertSame('4.18.1', self::row('outdated', fixed: 'x', latest: '4.18.1', suggested: 'y')->target(), 'The latest release.');
        self::assertSame('24', self::row('eol', fixed: null, latest: '20.20.2', suggested: '24')->target(), 'The newest supported cycle, not 20.20.2.');
        self::assertNull(self::row('eol', fixed: null, latest: '24.22.3', suggested: null)->target(), 'Already the newest supported cycle, or none supported.');
        self::assertNull(self::row('drift', fixed: '1', latest: '2', suggested: '3')->target(), 'An unknown kind aims nowhere, never breaks a list.');
    }

    private static function row(string $kind, ?string $fixed, ?string $latest, ?string $suggested = null): FindingRow
    {
        return new FindingRow(
            'f1', 't1', 'application', 'a1', $kind, 'T', 'p', '20.20.2', $fixed, null, null, false, null, null, null, $latest, [], 'open',
            new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), null, null, suggested: $suggested,
        );
    }
}
