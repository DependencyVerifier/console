<?php

declare(strict_types=1);

namespace Organization\Application\Query;

use Organization\Application\Query\View\MemberRow;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Query\Page;

/**
 * Emails of the members, asked to Identity through its published query (no table shared between contexts).
 */
final readonly class MemberEmails
{
    public function __construct(private QueryBus $queries)
    {
    }

    /**
     * @param Page<MemberRow> $page
     *
     * @return Page<MemberRow>
     */
    public function fill(Page $page): Page
    {
        if ([] === $page->items) {
            return $page;
        }

        $accounts = $this->queries->ask(new FindAccounts(array_map(static fn (MemberRow $row): string => $row->userId, $page->items)));

        return new Page(array_map(static fn (MemberRow $row): MemberRow => new MemberRow(
            $row->userId,
            isset($accounts[$row->userId]) ? $accounts[$row->userId]->email : '',
            $row->role,
            $row->extras,
            $row->active,
            $row->projects,
            $row->joinedAt,
        ), $page->items), $page->filtered, $page->total);
    }
}
