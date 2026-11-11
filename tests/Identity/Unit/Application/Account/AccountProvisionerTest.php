<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Account;

use Identity\Application\Account\AccountInvitation;
use Identity\Application\Account\AccountProvisioner;
use Identity\Application\Query\User\Handler\SearchAccountsHandler;
use Identity\Application\Query\User\UserDirectory;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Event\AccountSetupRequested;
use Identity\Domain\Model\AccountNotAssignable;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Contract\Identity\AccountMatch;
use Shared\Application\Contract\Identity\ProvisionedAccount;
use Shared\Application\Contract\Identity\SearchAccounts;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\InMemoryResetRequests;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\Users;

/**
 * Identity's published port and query for the team creation: the first admin's account, existing or invited, and the
 * account picker.
 */
final class AccountProvisionerTest extends TestCase
{
    private const string REQUEST = '019a6b2c-7f00-7a11-8b22-3c44d55e6f09';

    #[Test]
    public function it_should_return_an_existing_account_without_inviting_it(): void
    {
        [$users, $requests, $lock] = [new InMemoryUsers(Users::member(Users::OTHER_ID, 'john@example.com')), new InMemoryResetRequests(), new RecordingLock()];

        $account = $this->provisioner($users, $requests, $lock)->teamAdminAccount('John@Example.com', Users::THIRD_ID, self::REQUEST);

        self::assertEquals(new ProvisionedAccount(Users::OTHER_ID, 'john@example.com', false), $account);
        self::assertSame(['identity.email:john@example.com'], $lock->keys);
        self::assertCount(1, $users->users);
        self::assertSame([], $requests->requests);
    }

    #[Test]
    public function it_should_invite_an_unknown_email_with_a_setup_link(): void
    {
        [$users, $requests, $lock] = [new InMemoryUsers(), new InMemoryResetRequests(), new RecordingLock()];

        $account = $this->provisioner($users, $requests, $lock)->teamAdminAccount('New@Example.com', Users::THIRD_ID, self::REQUEST);

        self::assertEquals(new ProvisionedAccount(Users::THIRD_ID, 'new@example.com', true), $account);
        self::assertSame(['identity.email:new@example.com'], $lock->keys);
        $user = $users->find(UserId::fromString(Users::THIRD_ID));
        self::assertNotNull($user);
        self::assertTrue($user->awaitsPassword());
        self::assertTrue($user->maySetPassword());
        $request = $requests->find(Uuid::fromString(self::REQUEST));
        self::assertNotNull($request);
        self::assertTrue($request->userId()->equals($user->id()));
        self::assertSame('h:url-token', new \ReflectionProperty(PasswordResetRequest::class, 'tokenHash')->getValue($request), 'Only the hash of the token is kept.');
        self::assertTrue($request->isUsableAt(new \DateTimeImmutable('2026-11-16 07:59:59')), 'A 7-day setup link.');
        self::assertEquals([new AccountSetupRequested(self::REQUEST, Users::THIRD_ID, 'url-token')], $request->releaseEvents());
    }

    #[Test]
    public function it_should_refuse_an_account_that_cannot_sign_in_or_an_actor_without_the_permission(): void
    {
        $clock = new MockClock('2026-11-09 08:00:00');
        $deactivated = Users::member(Users::OTHER_ID, 'off@example.com');
        $deactivated->deactivate($clock->now());
        $deleted = Users::member(Users::ID, 'gone@example.com');
        $deleted->delete($clock->now());
        $users = new InMemoryUsers($deactivated, $deleted);
        $requests = new InMemoryResetRequests();
        foreach (['off@example.com', 'gone@example.com'] as $email) {
            try {
                $this->provisioner($users, $requests, new RecordingLock())->teamAdminAccount($email, Users::THIRD_ID, self::REQUEST);
                self::fail($email);
            } catch (AccountNotAssignable) {
            }
        }
        self::assertCount(2, $users->users);
        self::assertSame([], $requests->requests);

        $lock = new RecordingLock();
        try {
            $this->provisioner($users, $requests, $lock, new Grants())->teamAdminAccount('new@example.com', Users::THIRD_ID, self::REQUEST);
            self::fail('Not granted.');
        } catch (PermissionDenied) {
        }
        self::assertSame([], $lock->keys);
        self::assertCount(2, $users->users);
    }

    #[Test]
    public function it_should_search_assignable_accounts_for_administrators_only(): void
    {
        $directory = new class implements UserDirectory {
            /** @var list<array{string, int}> */
            public array $searched = [];

            public function page(PageRequest $page, bool $includeDeleted): Page
            {
                return new Page([], 0, 0);
            }

            public function byIds(array $userIds): array
            {
                return [];
            }

            public function matchIds(string $term, int $limit): array
            {
                return [];
            }

            public function search(string $term, int $limit): array
            {
                $this->searched[] = [$term, $limit];

                return [new AccountMatch(Users::ID, 'jane@example.com', 'Jane')];
            }
        };
        $search = new SearchAccountsHandler($directory, new Grants([Permission::InstanceUsersManage]));

        self::assertSame([], $search(new SearchAccounts(' j ')), 'Too short once trimmed.');
        self::assertEquals([new AccountMatch(Users::ID, 'jane@example.com', 'Jane')], $search(new SearchAccounts(' ja ')));
        self::assertSame([['ja', 10]], $directory->searched);

        $this->expectException(PermissionDenied::class);
        new SearchAccountsHandler($directory, new Grants())(new SearchAccounts('jane'));
    }

    private function provisioner(InMemoryUsers $users, InMemoryResetRequests $requests, RecordingLock $lock, ?Grants $grants = null): AccountProvisioner
    {
        $access = new AccountAccess(FixedActor::user(), $grants ?? new Grants([Permission::InstanceUsersManage]), $users);

        return new AccountProvisioner($access, $users, $lock, new AccountInvitation($users, $requests, new FixedTokens(), new PrefixTokenHasher(), new MockClock('2026-11-09 08:00:00')));
    }
}
