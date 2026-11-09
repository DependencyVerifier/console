<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Shared\Application\Text\SlugGenerator;

use function Symfony\Component\String\u;

final class FixedSlugs implements SlugGenerator
{
    public function unique(string $text, callable $isTaken): string
    {
        $slug = u($text)->lower()->replace(' ', '-')->toString();
        for ($i = 2, $candidate = $slug; $isTaken($candidate); ++$i) {
            $candidate = $slug.'-'.$i;
        }

        return $candidate;
    }
}
