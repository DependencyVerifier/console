<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application\Command;

use Identity\Application\Command\Administration\ConfirmEmailChange;
use Identity\Application\Command\Administration\Handler\ConfirmEmailChangeHandler;
use Identity\Application\Command\Administration\Handler\UpdateUserHandler;
use Identity\Application\Command\Administration\UpdateUser;
use Identity\Application\EventHandler\SendEmailChangeMessages;
use Identity\Application\Port\EmailChangeSender;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Event\EmailChanged;
use Identity\Domain\Event\EmailChangeRequested;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\EmailChangeLinkInvalid;
use Identity\Domain\Model\EmailChangeRequest;
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
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\RecordingLock;
use Tests\Identity\Support\Users;

final class UpdateUserHandlerTest extends TestCase
{
    private const string CHANGE = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';

    #[Test]
    public function it_should_rename_an_account_and_keep_its_email_when_unchanged(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        [$users, $changes, $lock] = [new InMemoryUsers($user), new InMemoryEmailChanges(), new RecordingLock()];
        $this->update($users, $changes, $lock)(new UpdateUser(Users::OTHER_ID, 'John', 'John@Example.com', self::CHANGE));

        self::assertSame('John', $user->displayName());
        self::assertSame([Users::OTHER_ID], $users->locked);
        self::assertSame([], $changes->requests, 'Same address: no link.');
        self::assertSame([], $lock->keys);
    }

    #[Test]
    public function it_should_send_a_link_to_a_new_address_and_replace_the_previous_one(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        [$users, $changes, $lock] = [new InMemoryUsers($user), new InMemoryEmailChanges(), new RecordingLock()];
        $previous = EmailChangeRequest::issue(Uuid::v7(), $user->id(), EmailAddress::of('old.try@example.com'), 't', 'h:old', new \DateTimeImmutable('2026-11-09 07:00:00'));
        $changes->add($previous);

        $this->update($users, $changes, $lock)(new UpdateUser(Users::OTHER_ID, '', 'John.Doe@Example.com', self::CHANGE));

        self::assertSame('john@example.com', $user->email()->value(), 'Not applied before the confirmation.');
        self::assertSame(['identity.email:john.doe@example.com'], $lock->keys);
        self::assertFalse($previous->isUsableAt(new \DateTimeImmutable('2026-11-09 08:00:00')));
        $request = $changes->find(Uuid::fromString(self::CHANGE));
        self::assertNotNull($request);
        self::assertSame('john.doe@example.com', $request->newEmail()->value());
        self::assertSame($request, $changes->findByTokenHash('h:url-token'), 'Only the hash of the token is kept.');
        self::assertEquals([new EmailChangeRequested(self::CHANGE, Users::OTHER_ID, 'john.doe@example.com', 'url-token')], $request->releaseEvents());
    }

    #[Test]
    public function it_should_refuse_a_taken_address_or_an_actor_without_the_permission(): void
    {
        $users = new InMemoryUsers(Users::member(), Users::member(Users::OTHER_ID, 'john@example.com'));
        $changes = new InMemoryEmailChanges();
        try {
            $this->update($users, $changes, new RecordingLock())(new UpdateUser(Users::OTHER_ID, '', 'jane@example.com', self::CHANGE));
            self::fail('Taken.');
        } catch (EmailAlreadyRegistered) {
        }
        self::assertSame([], $changes->requests);

        $this->expectException(PermissionDenied::class);
        new UpdateUserHandler(new AccountAccess(FixedActor::user(), new Grants(), $users), $users, $changes, new RecordingLock(), new FixedTokens(), new PrefixTokenHasher(), new MockClock())(new UpdateUser(Users::OTHER_ID, 'John', 'john@example.com', self::CHANGE));
    }

    #[Test]
    public function it_should_apply_a_confirmed_change_once(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        [$users, $changes, $lock] = [new InMemoryUsers($user), new InMemoryEmailChanges(), new RecordingLock()];
        $clock = new MockClock('2026-11-09 08:00:00');
        $changes->add(EmailChangeRequest::issue(Uuid::fromString(self::CHANGE), $user->id(), EmailAddress::of('john.doe@example.com'), 't', 'h:t', $clock->now()));
        $confirm = new ConfirmEmailChangeHandler($changes, $users, $lock, $clock);

        $confirm(new ConfirmEmailChange(self::CHANGE));
        self::assertSame('john.doe@example.com', $user->email()->value());
        self::assertSame(['identity.email:john.doe@example.com'], $lock->keys);
        self::assertSame([Users::OTHER_ID], $users->locked);

        foreach ([self::CHANGE, Uuid::v7()->toRfc4122()] as $id) {
            try {
                $confirm(new ConfirmEmailChange($id));
                self::fail('Used or unknown.');
            } catch (EmailChangeLinkInvalid) {
            }
        }
    }

    #[Test]
    public function it_should_refuse_a_change_to_an_address_taken_meanwhile_or_for_a_deleted_account(): void
    {
        $clock = new MockClock('2026-11-09 08:00:00');
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        $users = new InMemoryUsers($user, Users::member());
        $changes = new InMemoryEmailChanges();
        $request = EmailChangeRequest::issue(Uuid::fromString(self::CHANGE), $user->id(), EmailAddress::of('jane@example.com'), 't', 'h:t', $clock->now());
        $changes->add($request);
        try {
            new ConfirmEmailChangeHandler($changes, $users, new RecordingLock(), $clock)(new ConfirmEmailChange(self::CHANGE));
            self::fail('Taken meanwhile.');
        } catch (EmailAlreadyRegistered) {
        }
        self::assertSame('john@example.com', $user->email()->value());

        foreach (['deleted' => static fn () => $user->delete($clock->now()), 'gone' => static fn () => null] as $case => $setUp) {
            $setUp();
            $other = EmailChangeRequest::issue(Uuid::v7(), 'gone' === $case ? UserId::fromString(Users::THIRD_ID) : $user->id(), EmailAddress::of('free@example.com'), 't', 'h:'.$case, $clock->now());
            $changes->add($other);
            try {
                new ConfirmEmailChangeHandler($changes, $users, new RecordingLock(), $clock)(new ConfirmEmailChange($other->id()->toRfc4122()));
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

    private function update(InMemoryUsers $users, InMemoryEmailChanges $changes, RecordingLock $lock): UpdateUserHandler
    {
        return new UpdateUserHandler(new AccountAccess(FixedActor::user(), new Grants([Permission::InstanceUsersManage]), $users), $users, $changes, $lock, new FixedTokens(), new PrefixTokenHasher(), new MockClock('2026-11-09 08:00:00'));
    }
}
