<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Application\Agent\InventoryAgents;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Fleet\AgentContext;
use Shared\Application\Contract\Fleet\GetAgentContext;
use Symfony\Component\Clock\MockClock;
use Tests\Organization\Support\FixedQueries;

/**
 * Everything the Inventory handlers need, in memory, at 2026-11-09 08:02:00 UTC; the agent is Inv::AGENT,
 * approved, container agent of the Compose project `shop`, on Inv::HOST of Inv::TEAM.
 */
final class InventoryWorld
{
    public InMemoryRuns $runs;
    public InMemoryBlobs $blobs;
    public InMemoryHosts $hosts;
    public InMemoryApps $apps;
    public InMemoryImages $images;
    public InMemoryRecords $records;
    public InMemoryPackages $packages;
    public MockClock $clock;
    public FixedQueries $queries;

    /** @var array<string, AgentContext> */
    public array $agents = [];

    public function __construct()
    {
        $this->runs = new InMemoryRuns();
        $this->blobs = new InMemoryBlobs();
        $this->hosts = new InMemoryHosts();
        $this->apps = new InMemoryApps();
        $this->images = new InMemoryImages();
        $this->records = new InMemoryRecords();
        $this->packages = new InMemoryPackages();
        $this->clock = new MockClock('2026-11-09 08:02:00', 'UTC');
        $this->agent();
        $this->queries = new FixedQueries([
            GetAgentContext::class => fn (object $query): AgentContext => $query instanceof GetAgentContext && isset($this->agents[$query->agentId]) ? $this->agents[$query->agentId] : throw ResourceNotFound::of('agent'),
        ]);
    }

    public function agent(string $id = Inv::AGENT, string $status = 'approved', string $mode = 'container', ?string $scope = 'compose', ?string $project = 'shop', ?string $environment = Inv::ENVIRONMENT): self
    {
        $this->agents[$id] = new AgentContext($id, Inv::TEAM, Inv::HOST, $status, $mode, $scope, $project, $environment);

        return $this;
    }

    public function inventoryAgents(): InventoryAgents
    {
        return new InventoryAgents($this->queries, $this->runs);
    }
}
