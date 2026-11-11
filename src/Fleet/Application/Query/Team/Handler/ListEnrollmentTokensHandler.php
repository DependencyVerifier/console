<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team\Handler;

use Fleet\Application\Query\FleetReadModel;
use Fleet\Application\Query\Team\ListEnrollmentTokens;
use Fleet\Application\Query\View\TokenRow;
use Fleet\Application\Security\FleetAccess;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Query\Page;

/**
 * The tokens of the team, their environment named `project / environment` (asked to Organization).
 */
final readonly class ListEnrollmentTokensHandler implements QueryHandler
{
    public function __construct(private FleetAccess $access, private FleetReadModel $read, private QueryBus $queries, private ClockInterface $clock)
    {
    }

    /**
     * @return Page<TokenRow>
     */
    public function __invoke(ListEnrollmentTokens $query): Page
    {
        $this->access->view($query->teamId);
        $page = $this->read->tokens($query->teamId, $query->page, $this->clock->now());
        $names = [];
        foreach ($this->queries->ask(new ListTeamEnvironments($query->teamId)) as $environment) {
            $names[$environment->environmentId] = $environment->projectName.' / '.$environment->name;
        }

        return new Page(
            array_map(static fn (TokenRow $row): TokenRow => $row->withEnvironment($names[$row->environmentId ?? ''] ?? ''), $page->items),
            $page->filtered,
            $page->total,
        );
    }
}
