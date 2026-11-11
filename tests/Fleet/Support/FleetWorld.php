<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Application\Command\Agent\Handler\EnrollAgentHandler;
use Fleet\Application\Command\Agent\Handler\RecordAgentPollHandler;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Infrastructure\Crypto\SodiumConsoleSigner;
use Fleet\Infrastructure\Crypto\SodiumSignatureVerifier;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Symfony\Component\Clock\MockClock;
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
        $this->queries = new FixedQueries([GetTeamQuota::class => fn (object $query): ?int => $query instanceof GetTeamQuota ? ($this->limits[$query->quota] ?? null) : null]);
        $this->signer = new SodiumConsoleSigner(self::CONSOLE_SEED);
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
