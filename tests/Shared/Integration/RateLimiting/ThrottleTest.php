<?php

declare(strict_types=1);

namespace Tests\Shared\Integration\RateLimiting;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\Contended;
use Shared\Application\Security\ExclusiveSection;
use Shared\Application\Security\RateLimiterStorageUnavailable;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Shared\Infrastructure\RateLimiting\DbalRateLimiterStorage;
use Shared\Infrastructure\RateLimiting\LockExclusiveSection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\Uuid;
use Tests\Support\OwnerConnection;

/**
 * Real limiters on the real storage: peek refuses once the window is spent, the last accepted consumption is flagged,
 * states survive a rollback of the default connection, storage failures fail closed, and the exclusive section gives
 * up after 2 s under contention.
 */
final class ThrottleTest extends KernelTestCase
{
    #[Test]
    public function it_should_have_a_limiter_for_every_throttle_name(): void
    {
        self::bootKernel();
        $throttle = self::getContainer()->get('test.attempt_throttle');
        self::assertInstanceOf(AttemptThrottle::class, $throttle);
        $subject = ThrottleSubject::forAgent(Uuid::v7()->toRfc4122());

        foreach (ThrottleName::cases() as $name) {
            self::assertTrue($throttle->peek($name, $subject)->accepted, $name->value);
        }
    }

    #[Test]
    public function it_should_refuse_once_the_window_is_spent(): void
    {
        self::bootKernel();
        $throttle = self::getContainer()->get('test.attempt_throttle');
        self::assertInstanceOf(AttemptThrottle::class, $throttle);
        $subject = ThrottleSubject::forAccount(Uuid::v7()->toRfc4122());

        for ($i = 1; $i <= 4; ++$i) {
            self::assertTrue($throttle->peek(ThrottleName::SecondFactorShort, $subject)->accepted);
            $decision = $throttle->consume(ThrottleName::SecondFactorShort, $subject);
            self::assertTrue($decision->accepted);
            self::assertFalse($decision->justExhausted);
        }

        $fifth = $throttle->consume(ThrottleName::SecondFactorShort, $subject);
        self::assertTrue($fifth->accepted);
        self::assertTrue($fifth->justExhausted);
        self::assertFalse($throttle->peek(ThrottleName::SecondFactorShort, $subject)->accepted);
        self::assertFalse($throttle->consume(ThrottleName::SecondFactorShort, $subject)->accepted);
        self::assertGreaterThan(new \DateTimeImmutable(), $throttle->peek(ThrottleName::SecondFactorShort, $subject)->retryAfter);
    }

    #[Test]
    public function it_should_keep_a_consumption_when_the_command_transaction_rolls_back(): void
    {
        self::bootKernel();
        $throttle = self::getContainer()->get('test.attempt_throttle');
        self::assertInstanceOf(AttemptThrottle::class, $throttle);
        $default = self::getContainer()->get('doctrine.dbal.default_connection');
        $subject = ThrottleSubject::forAccount(Uuid::v7()->toRfc4122());

        $default->beginTransaction();
        for ($i = 0; $i < 5; ++$i) {
            $throttle->consume(ThrottleName::SecondFactorShort, $subject);
        }
        $default->rollBack();

        self::assertFalse($throttle->peek(ThrottleName::SecondFactorShort, $subject)->accepted);
    }

    #[Test]
    public function it_should_store_no_subject_in_clear(): void
    {
        self::bootKernel();
        $email = 'jane-'.Uuid::v7()->toBase58().'@example.com';
        $throttle = self::getContainer()->get('test.attempt_throttle');
        self::assertInstanceOf(AttemptThrottle::class, $throttle);
        $throttle->consume(ThrottleName::PasswordResetEmail, ThrottleSubject::forEmail($email));

        $rows = OwnerConnection::runtime()->fetchFirstColumn("SELECT id || ' ' || state FROM rate_limiter_state");
        foreach ($rows as $row) {
            self::assertIsString($row);
            self::assertStringNotContainsString($email, $row);
        }
    }

    #[Test]
    public function it_should_fail_closed_when_the_storage_is_unreachable(): void
    {
        $unreachable = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'nobody', 'dbname' => 'nowhere', 'serverVersion' => '18']);
        $storage = new DbalRateLimiterStorage($unreachable, new NativeClock());

        $this->expectException(RateLimiterStorageUnavailable::class);
        $storage->fetch('any');
    }

    #[Test]
    public function it_should_give_up_after_two_seconds_of_contention(): void
    {
        self::bootKernel();
        $section = self::getContainer()->get('test.exclusive_section');
        self::assertInstanceOf(ExclusiveSection::class, $section);
        $hasher = self::getContainer()->get('test.token_hasher');
        self::assertInstanceOf(TokenHasher::class, $hasher);
        $subject = ThrottleSubject::forIp('198.51.100.'.random_int(1, 254));

        $other = new LockExclusiveSection(
            new LockFactory(new DoctrineDbalPostgreSqlStore(OwnerConnection::runtime())),
            $hasher,
            new NativeClock(),
        );

        $start = microtime(true);
        $section->run($subject, static function () use ($other, $subject): void {
            try {
                $other->run($subject, static fn () => self::fail('The second session must not get the lock.'));
            } catch (Contended) {
                // expected
            }
        });

        self::assertGreaterThan(1.9, microtime(true) - $start);
        self::assertLessThan(3.0, microtime(true) - $start);
        $ran = false;
        $other->run($subject, static function () use (&$ran): void {
            $ran = true;
        });
        self::assertTrue($ran, 'The lock is released after the section.');
    }
}
