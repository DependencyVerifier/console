<?php

declare(strict_types=1);

namespace Tests\Instance\Unit\Domain\Model;

use Instance\Domain\Model\BuildLabel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class BuildLabelTest extends TestCase
{
    #[Test]
    public function it_should_derive_the_version_of_a_release_build(): void
    {
        $label = BuildLabel::fromRaw('1.1.2');

        self::assertSame('1.1.2', $label->label());
        self::assertSame('1.1.2', $label->version()?->toString());
        self::assertSame('1.1.2', BuildLabel::fromRaw(" 1.1.2\n")->version()?->toString(), 'Trimmed before reading the version.');
    }

    #[Test]
    public function it_should_keep_the_label_of_any_other_build_without_a_version(): void
    {
        foreach (['dev', 'v1.1.0-3-gabc123', ''] as $raw) {
            $label = BuildLabel::fromRaw($raw);
            self::assertSame($raw, $label->label());
            self::assertNull($label->version(), $raw);
        }
    }

    #[Test]
    public function it_should_truncate_a_long_label_to_64_characters(): void
    {
        $exact = u('a')->repeat(64)->toString();

        self::assertSame($exact, BuildLabel::fromRaw($exact)->label());
        self::assertSame($exact, BuildLabel::fromRaw($exact.'b')->label());
    }
}
