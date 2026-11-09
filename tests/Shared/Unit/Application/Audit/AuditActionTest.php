<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Application\Audit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\AuditAction;

use function Symfony\Component\String\u;

final class AuditActionTest extends TestCase
{
    #[Test]
    public function it_should_name_an_action_and_whitelist_its_context_keys(): void
    {
        $action = AuditAction::named('identity.login', ['reason', 'factor']);

        self::assertSame('identity.login', $action->name);
        self::assertSame(['reason', 'factor'], $action->allowedContextKeys);
        self::assertTrue($action->allows('factor'));
        self::assertFalse($action->allows('password'));
        self::assertSame([], AuditAction::named('a.b')->allowedContextKeys);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidNames(): iterable
    {
        yield 'no dot' => ['login'];
        yield 'upper case' => ['Identity.login'];
        yield 'trailing dot' => ['identity.'];
        yield 'leading space' => [' identity.login'];
        yield 'trailing space' => ['identity.login '];
        yield 'digit first' => ['1identity.login'];
    }

    #[Test]
    #[DataProvider('provideInvalidNames')]
    public function it_should_refuse_an_invalid_name(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditAction::named($name);
    }

    #[Test]
    public function it_should_accept_nested_names_with_digits_and_underscores(): void
    {
        self::assertSame('organization.invite_member2.resend', AuditAction::named('organization.invite_member2.resend')->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Reason'];
        yield 'dash' => ['the-reason'];
        yield 'leading space' => [' reason'];
        yield 'trailing space' => ['reason '];
        yield 'too long' => ['a'.u('b')->repeat(64)->toString()];
    }

    #[Test]
    #[DataProvider('provideInvalidKeys')]
    public function it_should_refuse_an_invalid_context_key(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditAction::named('a.b', ['valid', $key]);
    }

    #[Test]
    public function it_should_accept_a_64_characters_key(): void
    {
        $key = 'a'.u('b')->repeat(63)->toString();

        self::assertTrue(AuditAction::named('a.b', [$key])->allows($key));
    }
}
