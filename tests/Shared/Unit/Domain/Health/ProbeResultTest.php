<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Domain\Health;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Health\ProbeResult;

final class ProbeResultTest extends TestCase
{
    #[Test]
    public function it_should_describe_a_healthy_probe(): void
    {
        $result = ProbeResult::up('database');

        self::assertSame('database', $result->name);
        self::assertTrue($result->healthy);
        self::assertNull($result->failureReason);
    }

    #[Test]
    public function it_should_describe_a_failed_probe_with_its_reason(): void
    {
        $result = ProbeResult::down('database', 'Connection refused');

        self::assertSame('database', $result->name);
        self::assertFalse($result->healthy);
        self::assertSame('Connection refused', $result->failureReason);
    }
}
