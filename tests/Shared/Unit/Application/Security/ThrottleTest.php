<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Application\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleDecision;
use Shared\Application\Security\ThrottleSubject;

final class ThrottleTest extends TestCase
{
    #[Test]
    public function it_should_build_namespaced_subject_keys(): void
    {
        self::assertSame('account:u1', ThrottleSubject::forAccount('u1')->key);
        self::assertSame('email:jane@example.com', ThrottleSubject::forEmail('Jane@Example.com')->key);
        self::assertSame('ip:192.0.2.1', ThrottleSubject::forIp('192.0.2.1')->key);
        self::assertSame('team:t1', ThrottleSubject::forTeam('t1')->key);
        self::assertSame('invitation:i1', ThrottleSubject::forInvitation('i1')->key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBlankValues(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
    }

    #[Test]
    #[DataProvider('provideBlankValues')]
    public function it_should_refuse_a_blank_subject(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Empty ip throttle subject.');

        ThrottleSubject::forIp($value);
    }

    #[Test]
    public function it_should_describe_decisions(): void
    {
        $now = new \DateTimeImmutable();
        $later = $now->modify('+1 hour');

        $accepted = ThrottleDecision::accepted($now);
        self::assertTrue($accepted->accepted);
        self::assertFalse($accepted->justExhausted);
        self::assertSame($now, $accepted->retryAfter);

        $exhausted = ThrottleDecision::acceptedAndExhausted($later);
        self::assertTrue($exhausted->accepted);
        self::assertTrue($exhausted->justExhausted);
        self::assertSame($later, $exhausted->retryAfter);

        $refused = ThrottleDecision::refused($later);
        self::assertFalse($refused->accepted);
        self::assertFalse($refused->justExhausted);
        self::assertSame($later, $refused->retryAfter);
    }

    #[Test]
    public function it_should_carry_the_retry_time(): void
    {
        $later = new \DateTimeImmutable('+5 minutes');
        $throttled = new Throttled($later);

        self::assertSame($later, $throttled->retryAfter);
        self::assertSame('Too many attempts, try again later.', $throttled->getMessage());
    }
}
