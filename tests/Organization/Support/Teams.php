<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamQuotas;
use Organization\Domain\Model\TeamRole;
use Symfony\Component\Uid\Uuid;

final class Teams
{
    public const string TEAM = '019a6b2c-2f00-7a11-8b22-3c44d55e6f01';
    public const string OTHER_TEAM = '019a6b2c-2f00-7a11-8b22-3c44d55e6f02';
    public const string PROJECT = '019a6b2c-3f00-7a11-8b22-3c44d55e6f01';
    public const string ADMIN = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    public const string MEMBER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    public const string GUEST = '019a6b2c-1f00-7a11-8b22-3c44d55e6f03';

    public static function team(string $id = self::TEAM, ?TeamQuotas $quotas = null): Team
    {
        $team = Team::create(TeamId::fromString($id), 'Platform', 'platform', $quotas ?? TeamQuotas::defaults(), self::now());
        $team->releaseEvents();

        return $team;
    }

    public static function withAdmin(string $id = self::TEAM): Team
    {
        $team = self::team($id);
        $team->admit(Uuid::fromString(self::ADMIN), TeamRole::Admin, self::now());

        return $team;
    }

    public static function project(string $teamId = self::TEAM, string $id = self::PROJECT): Project
    {
        return Project::create(ProjectId::fromString($id), TeamId::fromString($teamId), 'Shop', 'shop', Uuid::fromString(self::ADMIN), TeamRole::Admin, self::now());
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-09 08:00:00');
    }

    public static function uuid(string $id): Uuid
    {
        return Uuid::fromString($id);
    }
}
