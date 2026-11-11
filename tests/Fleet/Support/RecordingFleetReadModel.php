<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Application\Query\FleetReadModel;
use Fleet\Application\Query\View\AgentRow;
use Fleet\Application\Query\View\HostRow;
use Fleet\Application\Query\View\TokenRow;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Answers fixed rows and records the team each handler let through.
 */
final class RecordingFleetReadModel implements FleetReadModel
{
    /** @var list<array{string, mixed}> */
    public array $calls = [];

    /** @var list<AgentRow> */
    public array $agents = [];

    /** @var list<HostRow> */
    public array $hosts = [];

    /** @var list<TokenRow> */
    public array $tokens = [];

    public function agents(?string $teamId, PageRequest $page): Page
    {
        $this->calls[] = ['agents', $teamId];

        return new Page($this->agents, \count($this->agents), 9);
    }

    public function hosts(string $teamId, PageRequest $page): Page
    {
        $this->calls[] = ['hosts', $teamId];

        return new Page($this->hosts, \count($this->hosts), 9);
    }

    public function tokens(string $teamId, PageRequest $page, \DateTimeImmutable $now): Page
    {
        $this->calls[] = ['tokens', [$teamId, $now->format(\DATE_ATOM)]];

        return new Page($this->tokens, \count($this->tokens), 9);
    }
}
