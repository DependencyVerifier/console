<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Administration\ChangeUserEmail;
use Identity\Application\Command\Administration\ConfirmEmailChange;
use Identity\Application\Command\Administration\Handler\ChangeUserEmailHandler;
use Identity\Application\Command\Administration\Handler\ConfirmEmailChangeHandler;
use Identity\Application\EventHandler\SendEmailChangeMessages;
use Identity\Application\Port\EmailChangeSender;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Event\EmailChanged;
use Identity\Domain\Event\EmailChangeRequested;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\EmailChangeLinkInvalid;
use Identity\Domain\Model\EmailChangeRequest;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\SuperAdminEmailLocked;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\InMemoryEmailChanges;
use Tests\Identity\Support\InMemoryResetRequests;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\Users;

/**
 * The email of an account changed from /admin/users: a link to the new address (ChangeUserEmail), applied once it is
 * clicked (ConfirmEmailChange). Never on a super admin.
 */
final class EmailChangeHandlersTest extends TestCase
{
    private const string CHANGE = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';

    #[Test]
    public function it_should_send_a_link_to_a_new_address_and_replace_the_previous_one(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        [$users, $changes, $lock] = [new InMemoryUsers($user), new InMemoryEmailChanges(), new RecordingLock()];
        $previous = EmailChangeRequest::issue(Uuid::v7(), $user->id(), EmailAddress::of('old.try@example.com'), 't', 'h:old', new \DateTimeImmutable('2026-11-09 07:00:00'));
        $changes->add($previous);

        $this->change($users, $changes, $lock)(new ChangeUserEmail(Users::OTHER_ID, 'John.Doe@Example.com', self::CHANGE));

        self::assertSame('john@example.com', $user->email()->value(), 'Not applied before the confirmation.');
        self::assertSame([Users::OTHER_ID], $users->locked);
        self::assertSame(['identity.email:john.doe@example.com'], $lock->keys);
        self::assertFalse($previous->isUsableAt(new \DateTimeImmutable('2026-11-09 08:00:00')));
        $request = $changes->find(Uuid::fromString(self::CHANGE));
        self::assertNotNull($request);
        self::assertSame('john.doe@example.com', $request->newEmail()->value());
        self::assertSame($request, $changes->findByTokenHash('h:url-token'), 'Only the hash of the token is kept.');
        self::assertEquals([new EmailChangeRequested(self::CHANGE, Users::OTHER_ID, 'john.doe@example.com', 'url-token')], $request->releaseEvents());
    }

    #[Test]
    public function it_should_do_nothing_for_the_same_address(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        [$users, $changes, $lock] = [new InMemoryUsers($user), new InMemoryEmailChanges(), new RecordingLock()];
        $previous = EmailChangeRequest::issue(Uuid::v7(), $user->id(), EmailAddress::of('old.try@example.com'), 't', 'h:old', new \DateTimeImmutable('2026-11-09 07:00:00'));
        $changes->add($previous);

        $this->change($users, $changes, $lock)(new ChangeUserEmail(Users::OTHER_ID, 'John@Example.com', self::CHANGE));

        self::assertNull($changes->find(Uuid::fromString(self::CHANGE)), 'Same address: no link.');
        self::assertSame([], $lock->keys);
        self::assertTrue($previous->isUsableAt(new \DateTimeImmutable('2026-11-09 07:30:00')), 'A pending change stays open.');
    }

    #[Test]
    public function it_should_refuse_a_taken_address_a_super_admin_or_an_actor_without_the_permission(): void
    {
        $users = new InMemoryUsers(Users::member(), Users::member(Users::OTHER_ID, 'john@example.com'), Users::superAdmin(Users::THIRD_ID));
        $changes = new InMemoryEmailChanges();
        $refusals = [
            'taken' => [new ChangeUserEmail(Users::OTHER_ID, 'jane@example.com', self::CHANGE), EmailAlreadyRegistered::class],
            'super admin' => [new ChangeUserEmail(Users::THIRD_ID, 'free@example.com', self::CHANGE), SuperAdminEmailLocked::class],
        ];
        foreach ($refusals as $case => [$command, $expected]) {
            $lock = new RecordingLock();
            try {
                $this->change($users, $changes, $lock)($command);
                self::fail($case);
            } catch (EmailAlreadyRegistered|SuperAdminEmailLocked $refusal) {
                self::assertSame($expected, $refusal::class, $case);
            }
            self::assertSame('super admin' === $case ? [] : ['identity.email:jane@example.com'], $lock->keys, $case);
        }
        self::assertSame([], $changes->requests);

        $this->expectException(PermissionDenied::class);
        new ChangeUserEmailHandler(new AccountAccess(FixedActor::user(), new Grants(), $users), $users, $changes, new RecordingLock(), new FixedTokens(), new PrefixTokenHasher(), new MockClock())(new ChangeUserEmail(Users::OTHER_ID, 'free@example.com', self::CHANGE));
    }

    #[Test]
    public function it_should_apply_a_confirmed_change_once_and_close_the_links_sent_to_the_previous_address(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        [$users, $changes, $lock, $resets] = [new InMemoryUsers($user), new InMemoryEmailChanges(), new RecordingLock(), new InMemoryResetRequests()];
        $clock = new MockClock('2026-11-09 08:00:00');
        $changes->add(EmailChangeRequest::issue(Uuid::fromString(self::CHANGE), $user->id(), EmailAddress::of('john.doe@example.com'), 't', 'h:t', $clock->now()));
        $reset = PasswordResetRequest::issue(Uuid::v7(), $user->id(), 'r', 'h:r', $clock->now());
        $setup = PasswordResetRequest::issueAccountSetup(Uuid::v7(), $user->id(), 's', 'h:s', $clock->now());
        $someoneElse = PasswordResetRequest::issue(Uuid::v7(), UserId::fromString(Users::ID), 'o', 'h:o', $clock->now());
        array_map($resets->add(...), [$reset, $setup, $someoneElse]);
        $confirm = new ConfirmEmailChangeHandler($changes, $users, $resets, $lock, $clock);

        $confirm(new ConfirmEmailChange(self::CHANGE));
        self::assertSame('john.doe@example.com', $user->email()->value());
        self::assertSame(['identity.email:john.doe@example.com'], $lock->keys);
        self::assertSame([Users::OTHER_ID], $users->locked);
        self::assertFalse($reset->isUsableAt($clock->now()), 'The reset link went to the previous address.');
        self::assertFalse($setup->isUsableAt($clock->now()), 'The setup link too.');
        self::assertTrue($someoneElse->isUsableAt($clock->now()));

        foreach ([self::CHANGE, Uuid::v7()->toRfc4122()] as $id) {
            try {
                $confirm(new ConfirmEmailChange($id));
                self::fail('Used or unknown.');
            } catch (EmailChangeLinkInvalid) {
            }
        }
    }

    #[Test]
    public function it_should_refuse_a_change_to_an_address_taken_meanwhile_a_deleted_account_or_one_made_super_admin(): void
    {
        $clock = new MockClock('2026-11-09 08:00:00');
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        $users = new InMemoryUsers($user, Users::member());
        $changes = new InMemoryEmailChanges();
        $resets = new InMemoryResetRequests();
        $reset = PasswordResetRequest::issue(Uuid::v7(), $user->id(), 'r', 'h:r', $clock->now());
        $resets->add($reset);
        $confirm = static fn (EmailChangeRequest $request) => new ConfirmEmailChangeHandler($changes, $users, $resets, new RecordingLock(), $clock)(new ConfirmEmailChange($request->id()->toRfc4122()));

        $taken = EmailChangeRequest::issue(Uuid::fromString(self::CHANGE), $user->id(), EmailAddress::of('jane@example.com'), 't', 'h:t', $clock->now());
        $changes->add($taken);
        try {
            $confirm($taken);
            self::fail('Taken meanwhile.');
        } catch (EmailAlreadyRegistered) {
        }
        self::assertSame('john@example.com', $user->email()->value());
        self::assertTrue($reset->isUsableAt($clock->now()), 'Nothing changed: the links stay open.');

        $user->grantSuperAdmin();
        $promoted = $this->request($changes, $user, 'promoted', $clock);
        try {
            $confirm($promoted);
            self::fail('Made super admin meanwhile.');
        } catch (SuperAdminEmailLocked) {
        }
        self::assertTrue($promoted->isUsableAt($clock->now()), 'Not consumed.');
        self::assertSame('john@example.com', $user->email()->value());
        $user->revokeSuperAdmin();

        $user->delete($clock->now());
        foreach (['deleted' => $user, 'gone' => Users::member(Users::THIRD_ID, 'gone@example.com')] as $case => $owner) {
            $other = $this->request($changes, $owner, $case, $clock);
            try {
                $confirm($other);
                self::fail($case);
            } catch (EmailChangeLinkInvalid) {
            }
            self::assertTrue($other->isUsableAt($clock->now()), 'Not consumed.');
        }
    }

    #[Test]
    public function it_should_send_the_link_and_alert_the_previous_address(): void
    {
        $sender = new class implements EmailChangeSender {
            /** @var list<string> */
            public array $sent = [];

            public function sendConfirmation(string $newEmail, string $token): void
            {
                $this->sent[] = 'link '.$newEmail.' '.$token;
            }

            public function alertPreviousAddress(string $previousEmail): void
            {
                $this->sent[] = 'alert '.$previousEmail;
            }
        };
        $handler = new SendEmailChangeMessages($sender);
        $handler(new EmailChangeRequested('r', Users::ID, 'new@example.com', 'plain'));
        $handler(new EmailChanged(Users::ID, 'old@example.com'));

        self::assertSame(['link new@example.com plain', 'alert old@example.com'], $sender->sent);
    }

    private function request(InMemoryEmailChanges $changes, User $owner, string $case, MockClock $clock): EmailChangeRequest
    {
        $request = EmailChangeRequest::issue(Uuid::v7(), $owner->id(), EmailAddress::of($case.'@example.org'), 't', 'h:'.$case, $clock->now());
        $changes->add($request);

        return $request;
    }

    private function change(InMemoryUsers $users, InMemoryEmailChanges $changes, RecordingLock $lock): ChangeUserEmailHandler
    {
        return new ChangeUserEmailHandler(new AccountAccess(FixedActor::user(), new Grants([Permission::InstanceUsersManage]), $users), $users, $changes, $lock, new FixedTokens(), new PrefixTokenHasher(), new MockClock('2026-11-09 08:00:00'));
    }
}
