<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Application\Command\Agent\Handler\EnrollAgentHandler;
use Fleet\Application\Command\Agent\Handler\RecordAgentPollHandler;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Application\Security\FleetAccess;
use Fleet\Infrastructure\Crypto\SodiumConsoleSigner;
use Fleet\Infrastructure\Crypto\SodiumSignatureVerifier;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\FindTeamEnvironment;
use Shared\Application\Contract\Organization\FindTeamNames;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedRequest;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\ScriptedThrottle;
use Tests\Organization\Support\FixedQueries;

/**
 * Everything the Fleet handlers need, in memory, at 2026-11-09 08:00:00 UTC. Quota limits come from $limits
 * (absent: unlimited); the console key is the order vector's.
 */
final class FleetWorld
{
    public const string CONSOLE_SEED = 'TM0Imyj/ltqdtsNG7BFOD1uKMZ81q6Yk2oz27U+4pvs=';

    public InMemoryTokens $tokens;
    public InMemoryHosts $hosts;
    public InMemoryAgents $agents;
    public InMemoryOrders $orders;
    public RecordingNonces $nonces;
    public RecordingLock $lock;
    public ScriptedThrottle $throttle;
    public MockClock $clock;
    public FixedQueries $queries;
    public SodiumConsoleSigner $signer;

    /** @var array<string, int> */
    public array $limits = [];

    /** @var array<string, TeamAccess> teams the actor belongs to */
    public array $teams = [];

    /** @var list<TeamEnvironment> environments of Fleet::TEAM */
    public array $environments = [];

    public GrantedPermissions $permissions;
    public FixedActor $actor;

    public function __construct(public ?string $ip = '203.0.113.7')
    {
        $this->tokens = new InMemoryTokens();
        $this->hosts = new InMemoryHosts();
        $this->agents = new InMemoryAgents();
        $this->orders = new InMemoryOrders();
        $this->nonces = new RecordingNonces();
        $this->lock = new RecordingLock();
        $this->throttle = new ScriptedThrottle();
        $this->clock = new MockClock('2026-11-09 08:00:00', 'UTC');
        $this->permissions = new GrantedPermissions();
        $this->actor = FixedActor::user(Fleet::ADMIN);
        $this->queries = new FixedQueries([
            GetTeamQuota::class => fn (object $query): ?int => $query instanceof GetTeamQuota ? ($this->limits[$query->quota] ?? null) : null,
            GetTeamAccess::class => fn (object $query): TeamAccess => $query instanceof GetTeamAccess && isset($this->teams[$query->teamId]) ? $this->teams[$query->teamId] : throw ResourceNotFound::of('team'),
            FindTeamEnvironment::class => fn (object $query): ?TeamEnvironment => $query instanceof FindTeamEnvironment && Fleet::TEAM === $query->teamId
                ? array_find($this->environments, static fn (TeamEnvironment $e): bool => $e->environmentId === $query->environmentId) : null,
            ListTeamEnvironments::class => fn (object $query): array => $query instanceof ListTeamEnvironments && Fleet::TEAM === $query->teamId ? $this->environments : [],
            FindTeamNames::class => static fn (object $query): array => $query instanceof FindTeamNames ? array_intersect_key([Fleet::TEAM => 'Platform'], array_flip($query->teamIds)) : [],
        ]);
        $this->signer = new SodiumConsoleSigner(self::CONSOLE_SEED);
    }

    /**
     * The actor is a member of Fleet::TEAM with this role, holding these permissions there.
     */
    public function as(?string $role, Permission ...$permissions): self
    {
        $this->teams[Fleet::TEAM] = new TeamAccess(Fleet::TEAM, 'Platform', $role, false);
        $this->permissions->team[Fleet::TEAM] = array_values($permissions);

        return $this;
    }

    public function access(): FleetAccess
    {
        return new FleetAccess($this->queries, $this->permissions);
    }

    public function verifier(): RequestVerifier
    {
        return new RequestVerifier(new SodiumSignatureVerifier(), $this->nonces, $this->clock);
    }

    public function quotas(): FleetQuotas
    {
        return new FleetQuotas($this->lock, $this->queries, $this->agents, $this->tokens, $this->clock);
    }

    public function enroll(): EnrollAgentHandler
    {
        return new EnrollAgentHandler($this->verifier(), $this->tokens, $this->agents, $this->hosts, $this->quotas(), new PrefixTokenHasher(), $this->throttle, new FixedRequest($this->ip), $this->clock);
    }

    public function poll(): RecordAgentPollHandler
    {
        return new RecordAgentPollHandler($this->agents, $this->hosts, $this->orders, $this->quotas(), new OrderSigner($this->signer), $this->clock);
    }

    public function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
