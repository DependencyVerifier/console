<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Application\Authorization;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;

final class RefusalsTest extends TestCase
{
    #[Test]
    public function it_should_name_the_missing_permission_or_resource(): void
    {
        self::assertSame('Permission "team.edit" required.', PermissionDenied::for('team.edit')->getMessage());
        self::assertSame('Project not found.', ResourceNotFound::of('Project')->getMessage());
    }
}
