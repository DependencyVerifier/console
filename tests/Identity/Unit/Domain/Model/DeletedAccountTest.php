<?php

declare(strict_types=1);

namespace Tests\Identity\Unit\Domain\Model;

use Identity\Domain\Event\UserDeactivated;
use Identity\Domain\Model\AccountDeleted;
use Identity\Domain\Model\AccountStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Identity\Support\Users;

/**
 * Soft delete: a state of its own, distinct from deactivated, reversible through restore (as deactivated).
 */
final class DeletedAccountTest extends TestCase
{
    private const string NOW = '2026-11-09 08:00:00';

    #[Test]
    public function it_should_delete_an_active_account_like_a_deactivation_once(): void
    {
        $user = Users::member();
        $now = new \DateTimeImmutable(self::NOW);
        $user->delete($now);
        $user->delete($now->modify('+1 day'));

        self::assertTrue($user->status()->isDeleted());
        self::assertFalse($user->status()->isActive());
        self::assertFalse($user->canLogIn());
        self::assertFalse($user->maySetPassword());
        self::assertSame($now, $user->status()->disabledSince());
        self::assertSame(2, $user->sessionVersions()->authVersion(), 'Sessions revoked once.');
        self::assertEquals([new UserDeactivated(Users::ID)], $user->releaseEvents(), 'Memberships suspended once.');
    }

    #[Test]
    public function it_should_only_hide_an_account_already_deactivated(): void
    {
        $user = Users::member();
        $user->deactivate(new \DateTimeImmutable(self::NOW));
        $user->releaseEvents();
        $user->delete(new \DateTimeImmutable(self::NOW));

        self::assertTrue($user->status()->isDeleted());
        self::assertSame(2, $user->sessionVersions()->authVersion(), 'Nothing more to revoke.');
        self::assertSame([], $user->releaseEvents());

        $user->deactivate(new \DateTimeImmutable(self::NOW));
        self::assertTrue($user->status()->isDeleted(), 'Deactivating a deleted account changes nothing.');
    }

    #[Test]
    public function it_should_restore_as_deactivated_then_reactivate(): void
    {
        $user = Users::member();
        $user->delete(new \DateTimeImmutable(self::NOW));
        $user->releaseEvents();

        try {
            $user->reactivate();
            self::fail('Restore first.');
        } catch (AccountDeleted $refused) {
            self::assertSame('account_deleted', $refused->ruleKey());
        }

        $restoredAt = new \DateTimeImmutable('2026-11-10 08:00:00');
        $user->restore($restoredAt);
        self::assertFalse($user->status()->isDeleted());
        self::assertFalse($user->status()->isActive());
        self::assertSame($restoredAt, $user->status()->disabledSince());
        self::assertSame([], $user->releaseEvents(), 'Restoring gives no access back.');

        $user->restore(new \DateTimeImmutable('2026-11-11 08:00:00'));
        self::assertSame($restoredAt, $user->status()->disabledSince(), 'Only a deleted account is restored.');

        $user->reactivate();
        self::assertTrue($user->canLogIn());
    }

    #[Test]
    public function it_should_tell_the_deleted_state_apart(): void
    {
        self::assertTrue(AccountStatus::deleted(new \DateTimeImmutable())->isDeleted());
        self::assertFalse(AccountStatus::disabled(new \DateTimeImmutable())->isDeleted());
        self::assertFalse(AccountStatus::active()->isDeleted());
    }
}
