<?php

declare(strict_types=1);

namespace Tests\Exposure\Integration;

use Exposure\Application\Query\View\FindingRow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Support\DataTables;
use Twig\Environment;

use function Symfony\Component\String\u;

/**
 * The "Installed → target" cell of every findings list and of the finding page.
 */
final class FindingVersionsCellTest extends KernelTestCase
{
    #[Test]
    public function it_should_show_the_target_of_each_kind_of_finding(): void
    {
        self::assertSame('20.20.2 → 24', $this->cell('eol', '20.20.2', latest: '20.20.2', suggested: '24'), 'An end of life aims at the newest supported cycle.');
        self::assertSame('24.22.3 → —', $this->cell('eol', '24.22.3', latest: '24.22.3'), 'No newer supported cycle.');
        self::assertSame('4.17.20 → 4.18.1', $this->cell('outdated', '4.17.20', latest: '4.18.1'));
        self::assertSame('4.17.20 → 4.17.21', $this->cell('vulnerability', '4.17.20', fixed: '4.17.21', latest: '4.18.1'));
        self::assertSame('4.17.20 (no fix yet)', $this->cell('vulnerability', '4.17.20'));
        self::assertSame('— → 24', $this->cell('eol', null, suggested: '24'));
        self::assertTrue(u($this->html('eol', '20.20.2', suggested: '<b>'))->containsAny('→ &lt;b&gt;'), 'Escaped: a cycle comes from a third party.');
    }

    private function cell(string $kind, ?string $installed, ?string $fixed = null, ?string $latest = null, ?string $suggested = null): string
    {
        return DataTables::cell($this->html($kind, $installed, $fixed, $latest, $suggested))->text();
    }

    private function html(string $kind, ?string $installed, ?string $fixed = null, ?string $latest = null, ?string $suggested = null): string
    {
        $row = new FindingRow(
            'f1', 't1', 'application', 'a1', $kind, 'T', 'p', $installed, $fixed, null, null, false, null, null, null, $latest, [], 'open',
            new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), null, null, suggested: $suggested,
        );
        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);

        return $twig->render('exposure/cells/finding_versions.html.twig', ['row' => $row]);
    }
}
