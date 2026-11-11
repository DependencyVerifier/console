<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent\Handler;

use Fleet\Application\Command\Agent\EnrollAgent;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\AgentRuntime;
use Fleet\Domain\Model\AgentScope;
use Fleet\Domain\Model\EnrollmentTokenInvalid;
use Fleet\Domain\Model\Host;
use Fleet\Domain\Model\HostFacts;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\InvalidPublicKey;
use Fleet\Domain\Model\RevokedAgent;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Fleet\Domain\Repository\HostRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Protocol\AgentPayload;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\RequestContextProvider;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Symfony\Component\Uid\Uuid;

/**
 * Enrollment, in this order: the IP limiter (token brute force), the signature with the key of the body (§4.3, the
 * keyid must be its fingerprint), the payload, the token (under the team lock), then the idempotent retry, the
 * maxAgents quota, the host (found or registered) and the agent. A single-use token is consumed by the first success.
 */
final readonly class EnrollAgentHandler implements CommandHandler
{
    public function __construct(
        private RequestVerifier $verifier,
        private EnrollmentTokenRepository $tokens,
        private AgentRepository $agents,
        private HostRepository $hosts,
        private FleetQuotas $quotas,
        private TokenHasher $hasher,
        private AttemptThrottle $throttle,
        private RequestContextProvider $requestContext,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EnrollAgent $command): void
    {
        $ip = $this->requestContext->current()->ip;
        if (null !== $ip) {
            $decision = $this->throttle->consume(ThrottleName::AgentEnrollmentIp, ThrottleSubject::forIp($ip));
            if (!$decision->accepted) {
                throw new Throttled($decision->retryAfter);
            }
        }

        $payload = AgentPayload::parse($command->request->body);
        $key = self::keyOf($payload);
        $this->verifier->verify($command->request, static fn (string $keyId): AgentPublicKey => $key);

        $hash = $this->hasher->hash($payload->string('enrollment_token'));
        $team = $this->tokens->findByHash($hash)?->teamId() ?? throw new EnrollmentTokenInvalid();
        $this->quotas->lock($team);
        // Read again under the lock: its uses and status are those of the last committed enrollment.
        $token = $this->tokens->findByHash($hash) ?? throw new EnrollmentTokenInvalid();
        $now = $this->clock->now();

        $enrolled = $this->agents->findEnrolled($token->id(), $key->fingerprint());
        if (null !== $enrolled) {
            // The same token and key: a retry of a successful enrollment answers the first agent.
            if ($enrolled->status()->countsInQuota() || $enrolled->isInactive()) {
                return;
            }

            throw new RevokedAgent();
        }

        $token->assertUsableAt($now);
        $runtime = self::runtimeOf($payload);
        $facts = self::factsOf($payload->object('host'));
        if (null !== $this->agents->findLiveByFingerprint($key->fingerprint())) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'This key already belongs to an enrolled agent.');
        }

        $this->quotas->assertAgentSeat($token->teamId());
        $token->consume($now);

        $identity = $facts->identityFor($runtime->mode);
        $host = $this->hosts->findByIdentity($token->teamId(), $identity);
        if (null === $host) {
            $host = Host::register(HostId::of(Uuid::v7()), $token->teamId(), $identity, $facts, $now);
            $this->hosts->add($host);
        } else {
            $host->refresh($facts, $now);
        }

        $this->agents->add(Agent::enroll(AgentId::fromString($command->agentId), $token, $host, $key, $runtime, $now));
    }

    private static function keyOf(AgentPayload $payload): AgentPublicKey
    {
        try {
            return AgentPublicKey::fromText($payload->string('public_key'));
        } catch (InvalidPublicKey) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'public_key is not an ed25519 key.');
        }
    }

    private static function runtimeOf(AgentPayload $payload): AgentRuntime
    {
        $mode = AgentMode::tryFrom($payload->string('mode')) ?? throw new ProtocolRefusal(Problem::InvalidPayload, 'Only the host and container modes are accepted.');
        $scope = $payload->optionalString('scope');

        return new AgentRuntime(
            $mode,
            null === $scope ? null : (AgentScope::tryFrom($scope) ?? throw new ProtocolRefusal(Problem::InvalidPayload, 'Unknown container scope.')),
            $payload->optionalString('compose_project'),
            $payload->string('agent_version'),
        );
    }

    private static function factsOf(AgentPayload $host): HostFacts
    {
        $os = $host->optionalObject('os');

        return new HostFacts(
            $host->string('hostname'),
            $os?->optionalString('id'),
            $os?->optionalString('version_id'),
            $os?->optionalString('pretty_name'),
            $host->optionalString('kernel'),
            $host->optionalString('arch'),
            $host->optionalString('docker_engine_id'),
            $host->optionalString('machine_id_hash'),
        );
    }
}
