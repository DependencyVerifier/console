<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Application;

use Identity\Application\EventHandler\PublishAccountIntegrationEvents;
use Identity\Application\EventHandler\SendEmailLoginCode;
use Identity\Application\EventHandler\SendPasswordResetLink;
use Identity\Application\EventHandler\SendSecurityAlert;
use Identity\Application\Port\SecurityAlert;
use Identity\Application\Query\SecondFactor\GetCodeVerificationOutcome;
use Identity\Application\Query\SecondFactor\Handler\GetCodeVerificationOutcomeHandler;
use Identity\Application\Query\User\GetUserProfile;
use Identity\Application\Query\User\Handler\FindAccountsHandler;
use Identity\Application\Query\User\Handler\GetUserProfileHandler;
use Identity\Application\Query\User\Handler\ListUsersHandler;
use Identity\Application\Query\User\ListUsers;
use Identity\Application\Query\User\UserDirectory;
use Identity\Application\Query\User\UserProfile;
use Identity\Application\Query\User\UserSummary;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Event\BackupCodesRegenerated;
use Identity\Domain\Event\EmailLoginCodeIssued;
use Identity\Domain\Event\PasswordChanged;
use Identity\Domain\Event\PasswordResetRequested;
use Identity\Domain\Event\SecondFactorReset;
use Identity\Domain\Event\SecondFactorThrottled;
use Identity\Domain\Event\SuperAdminGranted;
use Identity\Domain\Event\SuperAdminRevoked;
use Identity\Domain\Event\TotpDisabled;
use Identity\Domain\Event\TotpEnabled;
use Identity\Domain\Event\UserDeactivated;
use Identity\Domain\Event\UserReactivated;
use Identity\Domain\Model\CodeKind;
use Identity\Domain\Model\CodeVerificationAttempt;
use Identity\Domain\Model\CodeVerificationOutcome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Actor;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountDeactivated;
use Shared\Application\Contract\Identity\AccountReactivated;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\Grants;
use Tests\Identity\Support\InMemoryAttempts;
use Tests\Identity\Support\InMemoryUsers;
use Tests\Identity\Support\RecordingAlerts;
use Tests\Identity\Support\RecordingEventBus;
use Tests\Identity\Support\RecordingSenders;
use Tests\Identity\Support\Users;

final class QueriesAndReactionsTest extends TestCase
{
    #[Test]
    public function it_should_read_back_the_outcome_of_the_actor_attempt_only(): void
    {
        $attempts = new InMemoryAttempts();
        $id = Uuid::v7();
        $attempts->add(new CodeVerificationAttempt($id, Uuid::fromString(Users::ID), CodeKind::Totp, CodeVerificationOutcome::Exhausted, new \DateTimeImmutable()));
        $handler = static fn (FixedActor $actor): GetCodeVerificationOutcomeHandler => new GetCodeVerificationOutcomeHandler($attempts, new AccountAccess($actor, new Grants(), new InMemoryUsers()));

        self::assertSame(CodeVerificationOutcome::Exhausted, $handler(FixedActor::user())(new GetCodeVerificationOutcome($id->toRfc4122())));

        foreach ([[FixedActor::user(Users::OTHER_ID), $id], [FixedActor::user(), Uuid::v7()]] as [$actor, $attempt]) {
            try {
                $handler($actor)(new GetCodeVerificationOutcome($attempt->toRfc4122()));
                self::fail('Not found.');
            } catch (ResourceNotFound) {
            }
        }
    }

    #[Test]
    public function it_should_show_a_profile_to_its_holder(): void
    {
        $user = Users::withTotp(Users::superAdmin());
        $profile = new GetUserProfileHandler(new InMemoryUsers($user), FixedActor::user(), new Grants())(new GetUserProfile(Users::ID));

        self::assertEquals(new UserProfile(Users::ID, 'root@example.com', '', true, true, true, 'active_totp', 2, new \DateTimeImmutable('2026-11-09 08:00:00')), $profile);
        self::assertTrue($profile->isTotpActive());
        self::assertFalse($profile->isTotpPending());
    }

    #[Test]
    public function it_should_show_a_profile_to_an_administrator(): void
    {
        $user = Users::member(Users::OTHER_ID, 'john@example.com');
        $user->startTotpEnrollment('secret');
        $user->deactivate(new \DateTimeImmutable());
        $grants = new Grants([Permission::InstanceUsersManage]);

        $profile = new GetUserProfileHandler(new InMemoryUsers($user), FixedActor::user(), $grants)(new GetUserProfile(Users::OTHER_ID));

        self::assertFalse($profile->superAdmin);
        self::assertFalse($profile->active);
        self::assertFalse($profile->canLogIn);
        self::assertTrue($profile->isTotpPending());
        self::assertFalse($profile->isTotpActive());
        self::assertSame([Permission::InstanceUsersManage], $grants->asked);
    }

    #[Test]
    public function it_should_hide_a_profile_from_anyone_else(): void
    {
        $users = new InMemoryUsers(Users::member(Users::OTHER_ID, 'john@example.com'));
        foreach ([[FixedActor::user(), Users::OTHER_ID], [new FixedActor(Actor::anonymous()), Users::OTHER_ID], [FixedActor::user(), Users::ID]] as [$actor, $id]) {
            try {
                new GetUserProfileHandler($users, $actor, new Grants([Permission::InstanceAuditView]))(new GetUserProfile($id));
                self::fail('Hidden.');
            } catch (ResourceNotFound) {
            }
        }

        $this->expectException(ResourceNotFound::class);
        new GetUserProfileHandler(new InMemoryUsers(), FixedActor::user(), new Grants([Permission::InstanceUsersManage]))(new GetUserProfile(Users::ID));
    }

    #[Test]
    public function it_should_list_users_for_administrators_only(): void
    {
        $summary = new UserSummary(Users::ID, 'jane@example.com', '', false, true, false, true, false, false, new \DateTimeImmutable());
        $directory = new class([$summary]) implements UserDirectory {
            public ?PageRequest $asked = null;

            /** @var list<bool> */
            public array $includeDeleted = [];

            /**
             * @param list<UserSummary> $users
             */
            public function __construct(private readonly array $users)
            {
            }

            public function page(PageRequest $page, bool $includeDeleted): Page
            {
                $this->asked = $page;
                $this->includeDeleted[] = $includeDeleted;

                return new Page($this->users, 1, 1);
            }

            public function byIds(array $userIds): array
            {
                return [new AccountView(Users::ID, 'jane@example.com', true)];
            }
        };
        $request = new PageRequest(10, 5, 'email', true, 'jane');

        $page = new ListUsersHandler($directory, new Grants([Permission::InstanceUsersManage]))(new ListUsers($request));
        self::assertSame([$summary], $page->items);
        self::assertSame($request, $directory->asked);
        new ListUsersHandler($directory, new Grants([Permission::InstanceUsersManage]))(new ListUsers($request, true));
        self::assertSame([false, true], $directory->includeDeleted, 'Deleted accounts are hidden unless asked for.');

        $accounts = new FindAccountsHandler($directory)(new FindAccounts([Users::ID]));
        self::assertEquals([Users::ID => new AccountView(Users::ID, 'jane@example.com', true)], $accounts);

        $this->expectException(PermissionDenied::class);
        new ListUsersHandler($directory, new Grants([Permission::InstanceAuditView]))(new ListUsers());
    }

    #[Test]
    public function it_should_send_codes_and_links_after_the_commit(): void
    {
        $senders = new RecordingSenders();
        $users = new InMemoryUsers(Users::member());

        new SendEmailLoginCode($users, $senders)(new EmailLoginCodeIssued(Users::ID, '424242'));
        new SendPasswordResetLink($users, $senders)(new PasswordResetRequested('r', Users::ID, 'token'));
        new SendEmailLoginCode($users, $senders)(new EmailLoginCodeIssued(Users::OTHER_ID, '1'));
        new SendPasswordResetLink($users, $senders)(new PasswordResetRequested('r', Users::OTHER_ID, 't'));

        self::assertSame([[Users::ID, '424242'], [Users::ID, 'token']], $senders->sent);
    }

    #[Test]
    public function it_should_send_one_alert_per_sensitive_change(): void
    {
        $alerts = new RecordingAlerts();
        $handler = new SendSecurityAlert(new InMemoryUsers(Users::member()), $alerts);
        $events = [
            [new PasswordChanged(Users::ID), SecurityAlert::PasswordChanged],
            [new TotpEnabled(Users::ID), SecurityAlert::TotpEnabled],
            [new TotpDisabled(Users::ID), SecurityAlert::TotpDisabled],
            [new BackupCodesRegenerated(Users::ID), SecurityAlert::BackupCodesRegenerated],
            [new SecondFactorReset(Users::ID), SecurityAlert::SecondFactorReset],
            [new SuperAdminGranted(Users::ID), SecurityAlert::SuperAdminGranted],
            [new SuperAdminRevoked(Users::ID), SecurityAlert::SuperAdminRevoked],
            [new SecondFactorThrottled(Users::ID, new \DateTimeImmutable()), SecurityAlert::SecondFactorThrottled],
        ];
        foreach ($events as [$event, $_]) {
            $handler($event);
        }

        $handler(new PasswordChanged(Users::OTHER_ID));
        self::assertSame(array_map(static fn (array $pair): array => [Users::ID, $pair[1]], $events), $alerts->sent);
    }

    #[Test]
    public function it_should_publish_the_account_contracts(): void
    {
        $bus = new RecordingEventBus();
        $translator = new PublishAccountIntegrationEvents($bus);

        $translator(new UserDeactivated(Users::ID));
        $translator(new UserReactivated(Users::ID));

        self::assertEquals([new AccountDeactivated(Users::ID), new AccountReactivated(Users::ID)], $bus->published);
    }
}
