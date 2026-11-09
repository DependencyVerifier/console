<?php

declare(strict_types=1);

namespace Shared\Application\Text;

/**
 * Display slug, made unique with a numeric suffix (`acme`, `acme-2`…). Aggregates receive it already computed.
 */
interface SlugGenerator
{
    /**
     * @param callable(string): bool $isTaken
     */
    public function unique(string $text, callable $isTaken): string;
}
