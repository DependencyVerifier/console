<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Application\Agent\InventoryAgents;
use Inventory\Application\Command\Inventory\Handler\ProcessInventoryHandler;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Model\Manifest;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\SnapshotBlob;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Fleet\AgentContext;
use Shared\Application\Contract\Fleet\GetAgentContext;
use Shared\Application\Contract\Organization\FindTeamEnvironment;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
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

    /** @var list<string> environments of Inv::TEAM */
    public array $environments = [Inv::ENVIRONMENT];

    /** @var array<string, int> quota limits (absent: unlimited) */
    public array $limits = [];

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
            FindTeamEnvironment::class => fn (object $query): ?TeamEnvironment => $query instanceof FindTeamEnvironment && Inv::TEAM === $query->teamId && \in_array($query->environmentId, $this->environments, true)
                ? new TeamEnvironment($query->environmentId, 'production', '019a6b2c-3f00-7a11-8b22-3c44d55e6f01', 'Shop') : null,
            GetTeamQuota::class => fn (object $query): ?int => $query instanceof GetTeamQuota ? ($this->limits[$query->teamId.' '.$query->quota] ?? null) : null,
        ]);
    }

    public function agent(string $id = Inv::AGENT, string $status = 'approved', string $mode = 'container', ?string $scope = 'compose', ?string $project = 'shop', ?string $environment = Inv::ENVIRONMENT): self
    {
        $this->agents[$id] = new AgentContext($id, Inv::TEAM, Inv::HOST, $status, $mode, $scope, $project, $environment);

        return $this;
    }

    public function process(): ProcessInventoryHandler
    {
        return new ProcessInventoryHandler($this->runs, $this->blobs, $this->hosts, $this->apps, $this->images, $this->records, $this->packages, $this->queries, $this->clock);
    }

    /**
     * A completed inventory of Inv::AGENT whose documents are stored (gzip), keyed by their digest.
     *
     * @param array<string, string> $documents digest => document
     */
    public function completed(Manifest $manifest, array $documents, string $id = Inv::RUN, string $agentId = Inv::AGENT): InventoryRun
    {
        foreach ($documents as $digest => $document) {
            if (null === $this->blobs->find(Uuid::fromString(Inv::TEAM), Digest::of($digest))) {
                $this->blobs->add(SnapshotBlob::store(Uuid::v7(), Uuid::fromString(Inv::TEAM), Digest::of($digest), null === $manifest->refOf(Digest::of($digest)) ? MediaType::CycloneDx : $manifest->refOf(Digest::of($digest))->mediaType, 1, (string) gzencode($document), $this->clock->now()));
            }
        }

        $run = InventoryRun::open(Uuid::fromString($id), Uuid::fromString($agentId), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), $manifest, $this->clock->now());
        $run->complete($manifest->digests(), $this->clock->now());
        $run->releaseEvents();
        $this->runs->add($run);

        return $run;
    }

    public function inventoryAgents(): InventoryAgents
    {
        return new InventoryAgents($this->queries, $this->runs);
    }
}
