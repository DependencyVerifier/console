<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

use function Symfony\Component\String\u;

/**
 * No external resource: every Lucide icon named in a template or in PHP code is served from assets/icons/lucide,
 * never fetched from the Iconify API at runtime.
 */
final class VendoredIconsTest extends TestCase
{
    #[Test]
    public function it_should_vendor_every_icon_in_use(): void
    {
        $root = \dirname(__DIR__, 2);
        $missing = [];
        foreach (new Finder()->files()->in([$root.'/templates', $root.'/src'])->name(['*.twig', '*.php']) as $file) {
            /** @var array{0: list<string>, 1: list<string>} $matches */
            $matches = u($file->getContents())->match('/lucide:([a-z0-9-]+)/', \PREG_PATTERN_ORDER);
            foreach ($matches[1] as $icon) {
                if (!is_file(\sprintf('%s/assets/icons/lucide/%s.svg', $root, $icon))) {
                    $missing[] = $file->getRelativePathname().': '.$icon;
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)));
    }
}
