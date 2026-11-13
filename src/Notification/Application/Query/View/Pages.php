<?php

declare(strict_types=1);

namespace Notification\Application\Query\View;

use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

use function Symfony\Component\String\u;

/**
 * The lists of Notification are short (a team's webhooks, a project's rules and recipients): filtered and cut in
 * memory, in the order given.
 */
final class Pages
{
    /**
     * @template T
     *
     * @param list<T>             $rows
     * @param \Closure(T): string $text what the search looks into
     *
     * @return Page<T>
     */
    public static function of(array $rows, PageRequest $page, \Closure $text): Page
    {
        $search = u($page->search)->lower();
        $filtered = $search->isEmpty() ? $rows : array_values(array_filter($rows, static fn (mixed $row): bool => u($text($row))->lower()->containsAny($search->toString())));

        return new Page(\array_slice($filtered, $page->offset, $page->limit), \count($filtered), \count($rows));
    }
}
