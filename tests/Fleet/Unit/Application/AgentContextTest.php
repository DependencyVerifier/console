<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\EventHandler\PublishAgentIntegrationEvents;
use Fleet\Application\Query\Contract\Handler\FindTeamHostHandler;
use Fleet\Application\Query\Contract\Handler\GetAgentContextHandler;
use Fleet\Application\Query\Contract\Handler\ListSilentAgentsHandler;
use Fleet\Domain\Event\AgentEnrolled;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\AgentRuntime;
use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Fleet\AgentAwaitingApproval;
use Shared\Application\Contract\Fleet\AgentContext;
use Shared\Application\Contract\Fleet\FindTeamHost;
use Shared\Application\Contract\Fleet\GetAgentContext;
use Shared\Application\Contract\Fleet\ListSilentAgents;
use Shared\Application\Contract\Fleet\SilentAgent;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;
use Tests\Identity\Support\RecordingEventBus;

final class AgentContextTest extends TestCase
{
    #[Test]
    public function it_should_tell_another_context_what_it_needs_about_an_agent(): void
    {
        $world = new FleetWorld();
        $world->tokens->add(Fleet::single());
        $world->agents->add(Fleet::approved());
        $handler = new GetAgentContextHandler($world->agents, $world->tokens);

        $context = $handler(new GetAgentContext(Fleet::AGENT));
        self::assertEquals(new AgentContext(Fleet::AGENT, Fleet::TEAM, Fleet::HOST, 'approved', 'container', 'compose', 'shop', Fleet::ENVIRONMENT), $context);
        self::assertTrue($context->isApproved());
        self::assertTrue($context->isConfinedToComposeProject());

        $world->tokens->tokens = [];
        self::assertNull($handler(new GetAgentContext(Fleet::AGENT))->defaultEnvironmentId, 'Token gone: no default environment.');

        $token = EnrollmentToken::single(EnrollmentTokenId::fromString(Fleet::TOKEN), Uuid::fromString(Fleet::TEAM), null, 'h', Uuid::fromString(Fleet::ADMIN), Fleet::now());
        $world->tokens->add($token);
        $world->agents->add(Agent::enroll(AgentId::fromString(Fleet::ORDER), $token, Fleet::host(), AgentPublicKey::fromText(Fleet::KEY), new AgentRuntime(AgentMode::Host, null, null, '0.1.0'), Fleet::now()));
        $host = $handler(new GetAgentContext(Fleet::ORDER));
        self::assertSame(['host', null, null, null], [$host->mode, $host->scope, $host->composeProject, $host->defaultEnvironmentId]);
        foreach (['nope', Fleet::HOST] as $id) {
            try {
                $handler(new GetAgentContext($id));
                self::fail('Unknown agent.');
            } catch (ResourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_should_confine_compose_and_labeled_agents_only(): void
    {
        $context = static fn (string $status, string $mode, ?string $scope): AgentContext => new AgentContext('a', 't', 'h', $status, $mode, $scope, null, null);
        self::assertFalse($context('pending', 'container', 'compose')->isApproved());
        self::assertTrue($context('approved', 'container', 'labeled')->isConfinedToComposeProject());
        self::assertFalse($context('approved', 'container', 'host')->isConfinedToComposeProject());
        self::assertFalse($context('approved', 'host', null)->isConfinedToComposeProject());
        self::assertFalse($context('approved', 'host', 'compose')->isConfinedToComposeProject(), 'Only a container agent has a scope.');
        self::assertSame([true, false, false], [$context('approved', 'embedded', null)->isEmbedded(), $context('approved', 'host', null)->isEmbedded(), $context('approved', 'container', 'host')->isEmbedded()]);
        self::assertFalse($context('approved', 'embedded', null)->isConfinedToComposeProject());
    }

    #[Test]
    public function it_should_publish_the_agents_waiting_and_list_the_silent_ones(): void
    {
        $events = new RecordingEventBus();
        $publish = new PublishAgentIntegrationEvents($events);
        $publish(new AgentEnrolled(Fleet::AGENT, Fleet::TEAM, true, 'web-01'));
        $publish(new AgentEnrolled(Fleet::ORDER, Fleet::TEAM, false, 'web-02'));
        self::assertEquals([new AgentAwaitingApproval(Fleet::AGENT, Fleet::TEAM, 'web-01')], $events->published, 'Only one waiting for a human.');

        $world = new FleetWorld();
        $world->hosts->add(Fleet::host());
        $silent = Fleet::approved();
        $world->agents->add($silent);
        $handler = new ListSilentAgentsHandler($world->agents, $world->hosts);
        self::assertEquals([new SilentAgent(Fleet::AGENT, Fleet::TEAM, 'web-01', Fleet::now())], $handler(new ListSilentAgents(Fleet::now()->modify('+1 hour'))));
        self::assertSame([], $handler(new ListSilentAgents(Fleet::now()->modify('-1 hour'))), 'Heard from since.');
        self::assertSame([], $handler(new ListSilentAgents(Fleet::now()->modify('+1 hour'), 0)));
        self::assertSame(1000, new ListSilentAgents(Fleet::now())->limit);
        $world->hosts->hosts = [];
        self::assertSame('', $handler(new ListSilentAgents(Fleet::now()->modify('+1 hour')))[0]->hostname, 'Its host gone: no name.');
    }

    #[Test]
    public function it_should_name_a_host_of_the_team_only(): void
    {
        $world = new FleetWorld();
        $world->hosts->add(Fleet::host());
        $handler = new FindTeamHostHandler($world->hosts);

        self::assertSame('web-01', $handler(new FindTeamHost(Fleet::TEAM, Fleet::HOST)));
        self::assertNull($handler(new FindTeamHost(Fleet::OTHER_TEAM, Fleet::HOST)), 'Another team asks.');
        self::assertNull($handler(new FindTeamHost(Fleet::TEAM, Fleet::AGENT)));
        self::assertNull($handler(new FindTeamHost(Fleet::TEAM, 'nope')));
    }
}
