<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Audit;

use Shared\Application\Audit\Scope;

/**
 * Carries the audit scope of a failed command, resolved before the unit of work is cleared
 * (ResetUnitOfWorkOnFailureMiddleware), up to AuditFailureMiddleware. Never leaves the command bus.
 */
final class FailureWithScope extends \RuntimeException
{
    public function __construct(\Throwable $previous, public readonly Scope $scope)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
