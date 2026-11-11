<?php

declare(strict_types=1);

namespace Tests\Audit\Unit\Application\Query\AuditLog;

use Audit\Application\Query\AuditLog\AuditCursor;
use Audit\Application\Query\AuditLog\InvalidAuditCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

final class AuditCursorTest extends TestCase
{
    #[Test]
    public function it_should_round_trip_through_its_encoding(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $at = new \DateTimeImmutable('2026-11-09 08:00:00.123456+00:00');
        $encoded = AuditCursor::at($at, $id)->encode();

        self::assertDoesNotMatchRegularExpression('#[+/]#', $encoded);
        $decoded = AuditCursor::decode($encoded);
        self::assertSame($id, $decoded->id);
        self::assertSame($at->format('U.u'), $decoded->occurredAt->format('U.u'));
    }

    #[Test]
    public function it_should_decode_url_safe_characters(): void
    {
        $id = Uuid::v7()->toRfc4122();
        // A timestamp whose base64 contains "+" or "/" once encoded, replaced by "-" or "_".
        foreach (['1794211200.999999', '1794211263.000001', '1794211254.000000'] as $timestamp) {
            $raw = base64_encode($timestamp.'|'.$id);
            $safe = u($raw)->replace('+', '-')->replace('/', '_')->toString();
            self::assertSame($id, AuditCursor::decode($safe)->id);
            self::assertSame($timestamp, AuditCursor::decode($safe)->occurredAt->format('U.u'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidCursors(): iterable
    {
        yield 'not base64' => ['%%%'];
        yield 'no separator' => [base64_encode('1794211200.000000')];
        yield 'three parts' => [base64_encode('1794211200.000000|a|b')];
        yield 'bad timestamp' => [base64_encode('yesterday|'.Uuid::v7()->toRfc4122())];
        yield 'bad uuid' => [base64_encode('1794211200.000000|not-a-uuid')];
        yield 'bytes that are not UTF-8' => ['not-a-cursor'];
    }

    #[Test]
    #[DataProvider('provideInvalidCursors')]
    public function it_should_refuse_a_tampered_cursor(string $cursor): void
    {
        $this->expectException(InvalidAuditCursor::class);

        AuditCursor::decode($cursor);
    }

    #[Test]
    public function it_should_be_a_business_rule_violation(): void
    {
        $violation = new InvalidAuditCursor();

        self::assertSame('invalid_audit_cursor', $violation->ruleKey());
        self::assertSame('Invalid pagination cursor.', $violation->getMessage());
    }
}
