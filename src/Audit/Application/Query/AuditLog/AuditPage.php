<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog;

final readonly class AuditPage
{
    /**
     * @param list<AuditEntryView> $entries
     */
    public function __construct(public array $entries, public ?AuditCursor $next)
    {
    }
}
