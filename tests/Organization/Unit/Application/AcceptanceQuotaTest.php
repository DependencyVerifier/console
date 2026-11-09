<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Command\Invitation\Handler\AcceptInvitationHandler;
use Organization\Application\Command\QuotaInput;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationNotPending;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\QuotaExceeded;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\AcceptInvitation;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

/**
 * At acceptance, the member quota counts the active members plus the other pending invitations (never this one).
 */
final class AcceptanceQuotaTest extends TestCase
{
    private const string INVITATION = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function boundaries(): iterable
    {
        yield 'only this invitation pending, one seat left' => [2, 1, true];
        yield 'another invitation takes the last seat' => [2, 2, false];
        yield 'no seat, nothing pending' => [1, 0, false];
        yield 'two seats left, one other pending' => [3, 2, true];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function it_should_count_the_other_pending_invitations_only(int $maxMembers, int $pendingOfTeam, bool $accepted): void
    {
        [$world, $team, $queries] = $this->world($maxMembers);
        $world->invitations->pendingOfTeam = $pendingOfTeam;

        try {
            $this->handler($world, $queries)(new AcceptInvitation(self::INVITATION));
            self::assertTrue($accepted, 'Should have been refused.');
            self::assertTrue($team->isMember(Uuid::fromString(Teams::MEMBER)));
        } catch (QuotaExceeded) {
            self::assertFalse($accepted, 'Should have been accepted.');
            self::assertFalse($team->isMember(Uuid::fromString(Teams::MEMBER)));
        }

        self::assertEquals([new FindAccounts([Teams::MEMBER])], $queries->asked, 'Only the email of the actor.');
    }

    #[Test]
    public function it_should_refuse_when_the_team_is_gone(): void
    {
        [$world, , $queries] = $this->world(5);
        $world->teams->vanished = [Teams::TEAM];

        $this->expectException(InvitationNotPending::class);
        $this->handler($world, $queries)(new AcceptInvitation(self::INVITATION));
    }

    /**
     * @return array{World, Team, FixedQueries}
     */
    private function world(int $maxMembers): array
    {
        $world = new World();
        $world->actor = FixedActor::user(Teams::MEMBER);
        $team = Teams::withAdmin();
        $quotas = [];
        foreach (QuotaName::cases() as $name) {
            $quotas[$name->value] = 9;
        }
        $team->changeQuotas(QuotaInput::teamQuotas([...$quotas, QuotaName::MaxMembers->value => $maxMembers]));
        $world->teams->add($team);
        $world->invitations->add(Invitation::issue(InvitationId::fromString(self::INVITATION), 'jane@example.com', InvitationTarget::team(TeamId::fromString(Teams::TEAM), TeamRole::Member), Uuid::fromString(Teams::ADMIN), 't', 'h', $world->clock->now()));
        $queries = new FixedQueries([FindAccounts::class => static fn (object $query): array => $query instanceof FindAccounts && [Teams::MEMBER] === $query->userIds ? [Teams::MEMBER => new AccountView(Teams::MEMBER, 'jane@example.com', true)] : []]);

        return [$world, $team, $queries];
    }

    private function handler(World $world, FixedQueries $queries): AcceptInvitationHandler
    {
        return new AcceptInvitationHandler($world->actor, $queries, $world->invitations, $world->teams, $world->projects, $world->grants, $world->clock);
    }
}
