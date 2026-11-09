<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Administration\DeleteUser;
use Identity\Application\Command\Administration\Handler\DeleteUserHandler;
use Identity\Application\Command\Administration\Handler\RestoreUserHandler;
use Identity\Application\Command\Administration\RestoreUser;
use Identity\Application\Security\AccountAccess;
use Identity\Application\Security\LastSuperAdminGuard;
use Identity\Domain\Model\SelfManagementForbidden;
use Identity\Domain\Model\SuperAdminRevocationForbidden;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\Users;

final class DeleteUserHandlersTest extends TestCase
{
    #[Test]
    public function it_should_delete_then_restore_an_account(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        $users = new InMemoryUsers(Users::superAdmin(), $user);
        $lock = new RecordingLock();
        $clock = new MockClock('2026-11-09 08:00:00');

        new DeleteUserHandler($this->access($users), new LastSuperAdminGuard($lock, $users), $clock)(new DeleteUser(Users::OTHER_ID));
        self::assertTrue($user->status()->isDeleted());
        self::assertSame([LastSuperAdminGuard::LOCK], $lock->keys);
        self::assertSame([Users::OTHER_ID], $users->locked);

        $clock->modify('+1 day');
        new RestoreUserHandler($this->access($users), $clock)(new RestoreUser(Users::OTHER_ID));
        self::assertFalse($user->status()->isDeleted());
        self::assertEquals($clock->now(), $user->status()->disabledSince());
    }

    #[Test]
    public function it_should_refuse_without_the_permission_oneself_or_the_last_super_admin(): void
    {
        $users = new InMemoryUsers(Users::superAdmin(), Users::superAdmin(Users::OTHER_ID, 'other@example.com'));
        $none = new AccountAccess(FixedActor::user(), new Grants(), $users);

        foreach ([
            PermissionDenied::class => static fn () => new DeleteUserHandler($none, new LastSuperAdminGuard(new RecordingLock(), $users), new MockClock())(new DeleteUser(Users::OTHER_ID)),
            'restore' => static fn () => new RestoreUserHandler($none, new MockClock())(new RestoreUser(Users::OTHER_ID)),
            SelfManagementForbidden::class => fn () => new DeleteUserHandler($this->access($users), new LastSuperAdminGuard(new RecordingLock(), $users), new MockClock())(new DeleteUser(Users::ID)),
        ] as $refusal => $gesture) {
            try {
                $gesture();
                self::fail($refusal);
            } catch (PermissionDenied|SelfManagementForbidden) {
            }
        }

        $onlyOne = new InMemoryUsers(Users::superAdmin(Users::OTHER_ID, 'other@example.com'));
        $this->expectException(SuperAdminRevocationForbidden::class);
        new DeleteUserHandler($this->access($onlyOne), new LastSuperAdminGuard(new RecordingLock(), $onlyOne), new MockClock())(new DeleteUser(Users::OTHER_ID));
    }

    private function access(InMemoryUsers $users): AccountAccess
    {
        return new AccountAccess(FixedActor::user(), new Grants([Permission::InstanceUsersManage]), $users);
    }
}
