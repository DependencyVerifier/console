<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

use function Symfony\Component\String\u;

/**
 * The CSP has no 'unsafe-inline' for styles: no `style` attribute in the application templates. Emails are exempt
 * (mail clients need inline styles and the CSP does not apply to them).
 */
final class NoInlineStyleTest extends TestCase
{
    #[Test]
    public function it_should_keep_style_attributes_out_of_the_templates(): void
    {
        $offenders = [];
        $templates = new Finder()->files()->in(\dirname(__DIR__, 2).'/templates')->name('*.twig')->notPath('email');
        foreach ($templates as $template) {
            if ([] !== u($template->getContents())->match('/(\sstyle=["\']|[{,]\s*\'?style\'?:\s*["\'])/')) {
                $offenders[] = $template->getRelativePathname();
            }
        }

        self::assertSame([], $offenders);
    }
}
