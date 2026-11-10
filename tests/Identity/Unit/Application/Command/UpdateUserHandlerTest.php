<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Administration\Handler\UpdateUserHandler;
use Identity\Application\Command\Administration\UpdateUser;
use Identity\Application\Security\AccountAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Domain\Authorization\Permission;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\Users;

final class UpdateUserHandlerTest extends TestCase
{
    #[Test]
    public function it_should_rename_an_account_under_its_lock(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        $users = new InMemoryUsers($user);
        new UpdateUserHandler(new AccountAccess(FixedActor::user(), new Grants([Permission::InstanceUsersManage]), $users))(new UpdateUser(Users::OTHER_ID, 'John'));

        self::assertSame('John', $user->displayName());
        self::assertSame([Users::OTHER_ID], $users->locked);
    }

    #[Test]
    public function it_should_refuse_an_actor_without_the_permission(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        $users = new InMemoryUsers($user);
        try {
            new UpdateUserHandler(new AccountAccess(FixedActor::user(), new Grants(), $users))(new UpdateUser(Users::OTHER_ID, 'John'));
            self::fail('Not granted.');
        } catch (PermissionDenied) {
        }

        self::assertSame([], $users->locked);
        self::assertNotSame('John', $user->displayName());
    }
}
