<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog;

interface AuditLogReader
{
    public function page(AuditLogFilter $filter, ?AuditCursor $after, int $limit): AuditPage;
}
