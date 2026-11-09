<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Application\Audit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;

use function Symfony\Component\String\u;

final class AuditContextTest extends TestCase
{
    #[Test]
    public function it_should_hold_whitelisted_scalar_values(): void
    {
        $context = AuditContext::for(AuditAction::named('a.b', ['reason', 'count', 'flag']), ['reason' => 'é/x', 'count' => 3, 'flag' => true]);

        self::assertSame(['reason' => 'é/x', 'count' => 3, 'flag' => true], $context->values);
        self::assertSame('{"reason":"é/x","count":3,"flag":true}', $context->toJson());
    }

    #[Test]
    public function it_should_encode_an_empty_context_as_an_object(): void
    {
        self::assertSame('{}', AuditContext::empty()->toJson());
        self::assertSame([], AuditContext::empty()->values);
    }

    #[Test]
    public function it_should_refuse_a_key_the_action_does_not_allow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Context key "password" is not allowed for "a.b".');

        AuditContext::for(AuditAction::named('a.b', ['reason']), ['reason' => 'x', 'password' => 'secret']);
    }

    #[Test]
    public function it_should_count_bytes_not_characters(): void
    {
        $action = AuditAction::named('a.b', ['value']);

        $this->expectException(\LengthException::class);
        AuditContext::for($action, ['value' => u('é')->repeat(2100)->toString()]);
    }

    #[Test]
    public function it_should_refuse_more_than_4_kib(): void
    {
        $action = AuditAction::named('a.b', ['value']);
        $limit = AuditContext::MAX_BYTES - u('{"value":""}')->length();

        self::assertSame(AuditContext::MAX_BYTES, u(AuditContext::for($action, ['value' => u('x')->repeat($limit)->toString()])->toJson())->length());

        $this->expectException(\LengthException::class);
        AuditContext::for($action, ['value' => u('x')->repeat($limit + 1)->toString()]);
    }
}
