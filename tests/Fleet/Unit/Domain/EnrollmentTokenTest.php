<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Event\ReusableTokenApproved;
use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenExpired;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\EnrollmentTokenInvalid;
use Fleet\Domain\Model\EnrollmentTokenKind;
use Fleet\Domain\Model\EnrollmentTokenNotApprovable;
use Fleet\Domain\Model\EnrollmentTokenStatus;
use Fleet\Domain\Model\InvalidEnrollmentTokenSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AssertsRefusals;
use Tests\Fleet\Support\Fleet;

final class EnrollmentTokenTest extends TestCase
{
    use AssertsRefusals;

    #[Test]
    public function it_should_give_a_single_token_one_use_for_one_hour(): void
    {
        $token = Fleet::single();
        $now = Fleet::now();

        self::assertSame([Fleet::TOKEN, Fleet::TEAM, Fleet::ENVIRONMENT, EnrollmentTokenKind::Single, EnrollmentTokenStatus::Pending, 0, 1], [
            $token->id()->toString(), $token->teamId()->toRfc4122(), $token->environmentId()?->toRfc4122(), $token->kind(), $token->status(), $token->uses(), $token->maxUses(),
        ]);
        self::assertEquals($now->modify('+1 hour'), $token->expiresAt());
        self::assertEquals($now, $token->createdAt());
        self::assertSame(Fleet::ADMIN, $token->createdBy()->toRfc4122());
        self::assertFalse($token->isReusable());
        self::assertFalse($token->approvesAgents());
        self::assertTrue($token->isUsableAt($now->modify('+59 minutes 59 seconds')));
        self::assertFalse($token->isUsableAt($now->modify('+1 hour')), 'Expired at the second.');

        $token->consume($now);
        self::assertSame(1, $token->uses());
        self::assertFalse($token->isUsableAt($now), 'Used up.');
        $this->assertRefused(EnrollmentTokenInvalid::class, 'token_invalid', static fn () => $token->consume($now));
        $this->assertRefused(EnrollmentTokenNotApprovable::class, 'enrollment_token_not_approvable', static fn () => Fleet::single()->approve(Uuid::fromString(Fleet::ADMIN), $now));
    }

    #[Test]
    public function it_should_refuse_an_expired_token_at_the_second(): void
    {
        $token = Fleet::single();
        $token->consume(Fleet::now()->modify('+59 minutes 59 seconds'));

        $other = Fleet::single();
        $this->assertRefused(EnrollmentTokenExpired::class, 'token_expired', static fn () => $other->consume(Fleet::now()->modify('+1 hour')));
        self::assertSame(0, $other->uses());
    }

    #[Test]
    public function it_should_give_approved_agents_only_once_a_reusable_token_is_approved(): void
    {
        $token = Fleet::reusable(maxUses: 2);
        $now = Fleet::now();
        self::assertTrue($token->isReusable());
        self::assertFalse($token->approvesAgents());
        self::assertNull($token->approvedAt());

        $token->approve(Uuid::fromString(Fleet::ADMIN), $now);
        self::assertSame(EnrollmentTokenStatus::Approved, $token->status());
        self::assertTrue($token->approvesAgents());
        self::assertEquals($now, $token->approvedAt());
        self::assertSame(Fleet::ADMIN, $token->approvedBy()?->toRfc4122());
        self::assertEquals([new ReusableTokenApproved(Fleet::TOKEN, Fleet::TEAM)], $token->releaseEvents());
        $this->assertRefused(EnrollmentTokenNotApprovable::class, 'enrollment_token_not_approvable', static fn () => $token->approve(Uuid::fromString(Fleet::ADMIN), $now));

        $token->consume($now);
        $token->consume($now);
        self::assertFalse($token->isUsableAt($now));
        $this->assertRefused(EnrollmentTokenInvalid::class, 'token_invalid', static fn () => $token->consume($now));
    }

    #[Test]
    public function it_should_not_approve_a_token_that_can_no_longer_be_used(): void
    {
        $expired = Fleet::reusable();
        $this->assertRefused(EnrollmentTokenNotApprovable::class, 'enrollment_token_not_approvable', static fn () => $expired->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now()->modify('+30 days')));

        $revoked = Fleet::reusable();
        $revoked->revoke(Fleet::now());
        $this->assertRefused(EnrollmentTokenNotApprovable::class, 'enrollment_token_not_approvable', static fn () => $revoked->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now()));
    }

    #[Test]
    public function it_should_revoke_once_and_refuse_any_use_after(): void
    {
        $token = Fleet::reusable();
        $token->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now());
        $token->revoke(Fleet::now()->modify('+1 minute'));
        $token->revoke(Fleet::now()->modify('+2 minutes'));

        self::assertSame(EnrollmentTokenStatus::Revoked, $token->status());
        self::assertEquals(Fleet::now()->modify('+1 minute'), $token->revokedAt(), 'Idempotent: the first revocation stays.');
        self::assertFalse($token->approvesAgents());
        self::assertFalse($token->isUsableAt(Fleet::now()));
        $this->assertRefused(EnrollmentTokenInvalid::class, 'token_invalid', static fn () => $token->consume(Fleet::now()));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'expires now' => ['+0 seconds', 5];
        yield 'already expired' => ['-1 second', 5];
        yield 'beyond 90 days' => ['+90 days 1 second', 5];
        yield 'no use' => ['+1 day', 0];
        yield 'too many uses' => ['+1 day', 10001];
    }

    #[Test]
    #[DataProvider('invalidSettings')]
    public function it_should_bound_a_reusable_token(string $expiry, int $maxUses): void
    {
        $this->assertRefused(InvalidEnrollmentTokenSettings::class, 'invalid_enrollment_token_settings', static fn () => EnrollmentToken::reusable(
            EnrollmentTokenId::fromString(Fleet::TOKEN), Uuid::fromString(Fleet::TEAM), Uuid::fromString(Fleet::ENVIRONMENT), 'h', Uuid::fromString(Fleet::ADMIN), Fleet::now(), Fleet::now()->modify($expiry), $maxUses,
        ));
    }

    #[Test]
    public function it_should_accept_the_bounds_of_a_reusable_token(): void
    {
        $longest = EnrollmentToken::reusable(EnrollmentTokenId::fromString(Fleet::TOKEN), Uuid::fromString(Fleet::TEAM), Uuid::fromString(Fleet::ENVIRONMENT), 'h', Uuid::fromString(Fleet::ADMIN), Fleet::now(), Fleet::now()->modify('+90 days'), 10000);
        self::assertSame(10000, $longest->maxUses());
        self::assertSame(1, Fleet::reusable(maxUses: 1)->maxUses());
        self::assertEquals(Fleet::now()->modify('+30 days'), Fleet::reusable()->expiresAt());
    }
}
