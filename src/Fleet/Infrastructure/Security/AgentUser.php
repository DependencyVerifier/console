<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Security;

use Shared\Application\Audit\Actor;
use Shared\Infrastructure\Security\ActorAwareUser;
use Symfony\Component\Uid\Uuid;

/**
 * An agent authenticated by its signed request (stateless, one request): its writes are audited for the agent.
 */
final readonly class AgentUser implements ActorAwareUser
{
    public const string ROLE = 'ROLE_AGENT';

    public function __construct(public string $agentId)
    {
    }

    public function actor(): Actor
    {
        return Actor::agent(Uuid::fromString($this->agentId));
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function getUserIdentifier(): string
    {
        return 'agent:'.$this->agentId;
    }
}
