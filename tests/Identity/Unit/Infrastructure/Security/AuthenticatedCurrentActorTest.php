<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Infrastructure\Security;

use Identity\Infrastructure\Security\AuthenticatedCurrentActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Actor;
use Shared\Infrastructure\Security\ActorAwareUser;
use Shared\Infrastructure\Security\RuntimeCurrentActor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Uid\Uuid;

final class AuthenticatedCurrentActorTest extends TestCase
{
    #[Test]
    public function it_should_attribute_the_writes_of_an_authenticated_agent_to_the_agent(): void
    {
        $agentId = Uuid::fromString('019a6b2c-5f00-7a11-8b22-3c44d55e6f01');
        $agent = new readonly class($agentId) implements ActorAwareUser {
            public function __construct(private Uuid $id)
            {
            }

            public function actor(): Actor
            {
                return Actor::agent($this->id);
            }

            public function getRoles(): array
            {
                return ['ROLE_AGENT'];
            }

            public function getUserIdentifier(): string
            {
                return $this->id->toRfc4122();
            }
        };
        $requests = new RequestStack([new Request()]);
        $tokens = new TokenStorage();
        $current = new AuthenticatedCurrentActor($tokens, new RuntimeCurrentActor($requests));
        self::assertEquals(Actor::anonymous(), $current->actor());

        $tokens->setToken(new PreAuthenticatedToken($agent, 'agent', $agent->getRoles()));
        self::assertEquals(Actor::agent($agentId), $current->actor());
        self::assertFalse($current->isSuperAdmin());
        self::assertFalse($current->isFullyAuthenticated(), 'Never an account session.');
    }
}
