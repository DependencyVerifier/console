<?php

declare(strict_types=1);

namespace Fleet\Application\Query;

use Fleet\Application\Query\View\AgentRow;
use Fleet\Application\Query\View\HostRow;
use Fleet\Application\Query\View\OrderRow;
use Fleet\Application\Query\View\TokenRow;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Read side of Fleet (DBAL). It filters by the team it is given; the handlers decide which team the actor may see.
 */
interface FleetReadModel
{
    /**
     * @param string|null $teamId null: every team (instance view)
     *
     * @return Page<AgentRow> rows with an empty team name (filled by the handler)
     */
    public function agents(?string $teamId, PageRequest $page): Page;

    /**
     * @return Page<HostRow>
     */
    public function hosts(string $teamId, PageRequest $page): Page;

    /**
     * The orders of one agent, newest first.
     *
     * @return Page<OrderRow>
     */
    public function orders(string $agentId, PageRequest $page): Page;

    /**
     * @return Page<TokenRow> rows with an empty environment name (filled by the handler)
     */
    public function tokens(string $teamId, PageRequest $page, \DateTimeImmutable $now): Page;
}
