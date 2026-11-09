<?php

declare(strict_types=1);

namespace Shared\Application\Query;

use function Symfony\Component\String\u;

/**
 * One page of a list (DataTable server-side processing): offset, limit (capped), sort on a whitelisted column key,
 * free-text search. The handler decides which keys it accepts; an unknown key falls back to its default order.
 */
final readonly class PageRequest
{
    public const int MAX_LIMIT = 100;
    public const int MAX_SEARCH = 100;

    public int $offset;
    public int $limit;
    public string $search;

    public function __construct(int $offset = 0, int $limit = 25, public ?string $sort = null, public bool $descending = false, string $search = '')
    {
        $this->offset = max(0, $offset);
        $this->limit = max(1, min(self::MAX_LIMIT, $limit));
        $this->search = u($search)->trim()->truncate(self::MAX_SEARCH)->toString();
    }

    /**
     * The sort key when the handler accepts it, else its default.
     *
     * @param list<string> $allowed
     */
    public function sortAmong(array $allowed, string $default): string
    {
        return null !== $this->sort && \in_array($this->sort, $allowed, true) ? $this->sort : $default;
    }
}
