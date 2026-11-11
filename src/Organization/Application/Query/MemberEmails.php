<?php

declare(strict_types=1);

namespace Organization\Application\Query;

use Organization\Application\Query\View\MemberRow;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Identity\MatchAccounts;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

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

    /**
     * The accounts whose email or display name contains the searched term, asked to Identity; null without a search.
     *
     * @return list<string>|null
     */
    public function matching(PageRequest $page): ?array
    {
        return '' === $page->search ? null : $this->queries->ask(new MatchAccounts($page->search));
    }
}
