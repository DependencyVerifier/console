<?php

declare(strict_types=1);

namespace Tests\Audit\Unit\Application\Query\AuditLog;

use Audit\Application\Query\AuditLog\AuditCursor;
use Audit\Application\Query\AuditLog\AuditLogFilter;
use Audit\Application\Query\AuditLog\AuditLogReader;
use Audit\Application\Query\AuditLog\AuditPage;
use Audit\Application\Query\AuditLog\Handler\ListAuditEntriesHandler;
use Audit\Application\Query\AuditLog\ListAuditEntries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\OutcomeKind;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final class ListAuditEntriesHandlerTest extends TestCase
{
    #[Test]
    public function it_should_require_the_instance_permission_for_the_global_audit(): void
    {
        $reader = new SpyReader();
        $handler = new ListAuditEntriesHandler(new FixedPermissions([Permission::InstanceAuditView->value => true]), $reader);

        $page = $handler(new ListAuditEntries());

        self::assertSame($reader->page, $page);
        self::assertSame([[Permission::InstanceAuditView, null]], FixedPermissions::$asked);
        self::assertNull($reader->filter?->teamId);
        self::assertNull($reader->filter?->outcome);
        self::assertNull($reader->after);
        self::assertSame(50, $reader->limit);
        self::assertSame(ListAuditEntries::PAGE_SIZE, $reader->limit);
    }

    #[Test]
    public function it_should_require_the_team_permission_on_that_team(): void
    {
        $reader = new SpyReader();
        $cursor = AuditCursor::at(new \DateTimeImmutable('2026-11-09 08:00:00'), Uuid::v7()->toRfc4122());
        $handler = new ListAuditEntriesHandler(new FixedPermissions([Permission::TeamAuditView->value => true]), $reader);

        $handler(new ListAuditEntries('team-1', OutcomeKind::Denied, $cursor->encode()));

        self::assertSame([[Permission::TeamAuditView, 'team-1']], FixedPermissions::$asked);
        self::assertNotNull($reader->filter);
        self::assertSame('team-1', $reader->filter->teamId);
        self::assertSame(OutcomeKind::Denied, $reader->filter->outcome);
        self::assertSame($cursor->id, $reader->after?->id);
    }

    #[Test]
    public function it_should_refuse_without_the_permission(): void
    {
        $handler = new ListAuditEntriesHandler(new FixedPermissions([]), new SpyReader());

        $this->expectException(PermissionDenied::class);
        $this->expectExceptionMessageIsOrContains('Permission "team.audit.view" required.');

        $handler(new ListAuditEntries('team-1'));
    }
}

final class FixedPermissions implements PermissionChecker
{
    /** @var list<array{Permission, ?string}> */
    public static array $asked = [];

    /**
     * @param array<string, bool> $granted
     */
    public function __construct(private readonly array $granted)
    {
        self::$asked = [];
    }

    public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
    {
        self::$asked[] = [$permission, $teamId];

        return $this->granted[$permission->value] ?? false;
    }
}

final class SpyReader implements AuditLogReader
{
    public AuditPage $page;
    public ?AuditLogFilter $filter = null;
    public ?AuditCursor $after = null;
    public int $limit = 0;

    public function __construct()
    {
        $this->page = new AuditPage([], null);
    }

    public function page(AuditLogFilter $filter, ?AuditCursor $after, int $limit): AuditPage
    {
        [$this->filter, $this->after, $this->limit] = [$filter, $after, $limit];

        return $this->page;
    }
}
