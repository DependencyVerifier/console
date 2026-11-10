<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Event\EmailChanged;
use Identity\Domain\Event\EmailChangeRequested;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailChangeLinkInvalid;
use Identity\Domain\Model\EmailChangeRequest;
use Identity\Domain\Model\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\Users;

final class EmailChangeTest extends TestCase
{
    private const string NOW = '2026-11-09 08:00:00';

    #[Test]
    public function it_should_change_the_email_and_revoke_every_session(): void
    {
        $user = Users::member();
        $user->changeEmail(EmailAddress::of('jane.doe@example.com'));

        self::assertSame('jane.doe@example.com', $user->email()->value());
        self::assertSame(2, $user->sessionVersions()->authVersion());
        self::assertSame(2, $user->sessionVersions()->trustedDeviceVersion());
        self::assertEquals([new EmailChanged(Users::ID, 'jane@example.com')], $user->releaseEvents());

        $user->changeEmail(EmailAddress::of('Jane.Doe@Example.com'));
        self::assertSame(2, $user->sessionVersions()->authVersion(), 'The same address changes nothing.');
        self::assertSame([], $user->releaseEvents());
    }

    #[Test]
    public function it_should_issue_a_single_use_link_for_twenty_four_hours(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $id = Uuid::v7();
        $request = EmailChangeRequest::issue($id, UserId::fromString(Users::ID), EmailAddress::of('New@Example.com'), 'plain', 'hash', $now);

        self::assertSame($id, $request->id());
        self::assertTrue($request->userId()->equals(UserId::fromString(Users::ID)));
        self::assertSame('new@example.com', $request->newEmail()->value());
        self::assertEquals([new EmailChangeRequested($id->toRfc4122(), Users::ID, 'new@example.com', 'plain')], $request->releaseEvents());
        self::assertTrue($request->isUsableAt($now->modify('+24 hours -1 second')));
        self::assertFalse($request->isUsableAt($now->modify('+24 hours')));

        $request->consume($now);
        self::assertFalse($request->isUsableAt($now));
        $this->expectException(EmailChangeLinkInvalid::class);
        $request->consume($now);
    }

    #[Test]
    public function it_should_be_invalidated_by_a_newer_request(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $request = EmailChangeRequest::issue(Uuid::v7(), UserId::fromString(Users::ID), EmailAddress::of('new@example.com'), 'plain', 'hash', $now);
        $request->invalidate($now);
        $request->invalidate($now->modify('+1 hour'));

        self::assertFalse($request->isUsableAt($now));
        try {
            $request->consume($now);
            self::fail('Invalidated.');
        } catch (EmailChangeLinkInvalid $refused) {
            self::assertSame('email_change_link_invalid', $refused->ruleKey());
        }
    }

    #[Test]
    public function it_should_never_serialize_the_token(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new EmailChangeRequested('r', 'u', 'new@example.com', 'plain'));
    }
}
