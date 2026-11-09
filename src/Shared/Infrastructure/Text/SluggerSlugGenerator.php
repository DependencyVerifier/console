<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Text;

use Shared\Application\Text\SlugGenerator;
use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class SluggerSlugGenerator implements SlugGenerator
{
    public function __construct(private SluggerInterface $slugger)
    {
    }

    public function unique(string $text, callable $isTaken): string
    {
        $base = $this->slugger->slug($text)->lower()->toString();
        $base = '' !== $base ? $base : 'item';

        $slug = $base;
        for ($suffix = 2; $isTaken($slug); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
