<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Identity\Domain\Model\User;
use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamQuotas;
use Organization\Domain\Model\TeamRole;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;
use Tests\Support\Accounts;

use function Symfony\Component\String\u;

/**
 * Functional fixtures of Organization, written through the aggregates (no command, no audit entry).
 */
final class Seed
{
    /**
     * @param list<array{User, TeamRole}> $members
     */
    public static function team(ContainerInterface $container, string $name, array $members): Team
    {
        $team = Team::create(TeamId::of(Uuid::v7()), $name, u($name)->lower()->toString(), TeamQuotas::defaults(), new \DateTimeImmutable());
        foreach ($members as [$user, $role]) {
            $team->admit(self::uuid($user), $role, new \DateTimeImmutable());
        }

        $team->releaseEvents();
        Accounts::manager($container)->persist($team);
        Accounts::manager($container)->flush();

        return $team;
    }

    /**
     * @param list<array{User, ProjectRole}> $members
     */
    public static function project(ContainerInterface $container, Team $team, string $name, array $members = []): Project
    {
        $project = Project::create(ProjectId::of(Uuid::v7()), $team->id(), $name, u($name)->lower()->toString(), Uuid::v7(), TeamRole::Admin, new \DateTimeImmutable());
        foreach ($members as [$user, $role]) {
            $project->admit(self::uuid($user), $role, new \DateTimeImmutable());
        }

        Accounts::manager($container)->persist($project);
        Accounts::manager($container)->flush();

        return $project;
    }

    public static function uuid(User $user): Uuid
    {
        return Uuid::fromString($user->id()->toString());
    }
}
