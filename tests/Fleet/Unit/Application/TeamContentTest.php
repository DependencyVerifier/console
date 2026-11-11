<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Content\FleetTeamContent;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;

final class TeamContentTest extends TestCase
{
    #[Test]
    public function it_should_erase_the_fleet_of_a_deleted_team_only(): void
    {
        $world = new FleetWorld();
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $world->hosts->add(Fleet::host());
        $world->tokens->add(Fleet::single());
        $world->orders->add(AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $agent, null, Fleet::now()));
        $world->hosts->add(Fleet::host(Fleet::OTHER_TEAM, '019a6b2c-7f00-7a11-8b22-3c44d55e6f02'));
        $world->tokens->add(Fleet::single(Fleet::OTHER_TEAM, '019a6b2c-6f00-7a11-8b22-3c44d55e6f02'));

        $this->content($world)->eraseTeam(Fleet::TEAM);
        self::assertSame(['fleet.team:'.Fleet::TEAM], $world->lock->keys, 'The lock of the enrollments.');
        self::assertSame([[], [], ['019a6b2c-7f00-7a11-8b22-3c44d55e6f02'], ['019a6b2c-6f00-7a11-8b22-3c44d55e6f02']], [
            $world->orders->orders, $world->agents->agents, array_keys($world->hosts->hosts), array_keys($world->tokens->tokens),
        ]);
    }

    #[Test]
    public function it_should_keep_an_environment_that_a_usable_token_enrolls_into(): void
    {
        $world = new FleetWorld();
        $world->tokens->add(Fleet::single());
        $content = $this->content($world);

        self::assertTrue($content->environmentHasContent(Fleet::ENVIRONMENT));
        self::assertFalse($content->environmentHasContent(Fleet::TEAM));
        self::assertFalse($content->environmentHasContent('not-a-uuid'));
        self::assertFalse($content->projectHasContent(Fleet::ENVIRONMENT));
        $world->clock->modify('+1 hour');
        self::assertFalse($content->environmentHasContent(Fleet::ENVIRONMENT), 'Expired: the environment can go.');
    }

    private function content(FleetWorld $world): FleetTeamContent
    {
        return new FleetTeamContent($world->tokens, $world->hosts, $world->agents, $world->orders, $world->clock, $world->quotas());
    }
}
