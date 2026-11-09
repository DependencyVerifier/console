<?php

declare(strict_types=1);

namespace Shared\Application\Query;

/**
 * A page of results: the rows, the number of rows matching the search and the total before the search.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(public array $items, public int $filtered, public int $total)
    {
    }
}
