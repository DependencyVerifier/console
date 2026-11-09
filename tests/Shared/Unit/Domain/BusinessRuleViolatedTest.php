<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\BusinessRuleViolated;

use function Symfony\Component\String\u;

final class BusinessRuleViolatedTest extends TestCase
{
    #[Test]
    public function it_should_expose_its_rule_key_and_message(): void
    {
        $violation = self::violation('quota_exceeded', 'Quota reached.');

        self::assertSame('quota_exceeded', $violation->ruleKey());
        self::assertSame('Quota reached.', $violation->getMessage());
    }

    #[Test]
    public function it_should_default_the_message_to_the_rule_key(): void
    {
        self::assertSame('last_team_admin_required', self::violation('last_team_admin_required')->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'one character' => ['a'];
        yield 'upper case' => ['Quota'];
        yield 'leading digit' => ['1quota'];
        yield 'dash' => ['quota-exceeded'];
        yield 'prefix garbage' => [' quota'];
        yield 'suffix garbage' => ['quota '];
        yield 'too long' => ['a'.u('b')->repeat(64)->toString()];
    }

    #[Test]
    #[DataProvider('provideInvalidKeys')]
    public function it_should_refuse_an_invalid_rule_key(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::violation($key);
    }

    #[Test]
    public function it_should_accept_a_64_characters_key(): void
    {
        $key = 'a'.u('b')->repeat(63)->toString();

        self::assertSame($key, self::violation($key)->ruleKey());
    }

    private static function violation(string $key, string $message = ''): BusinessRuleViolated
    {
        return new class($key, $message) extends BusinessRuleViolated {};
    }
}
