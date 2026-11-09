<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

/**
 * One line of the append-only audit log.
 */
final readonly class AuditEntry
{
    public function __construct(
        public Actor $actor,
        public AuditAction $action,
        public Target $target,
        public Scope $scope,
        public Outcome $outcome,
        public RequestContext $request,
        public AuditContext $context,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
