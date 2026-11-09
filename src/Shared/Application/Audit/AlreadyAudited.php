<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

/**
 * What a bus throws for an audited refusal or failure. Immutable envelope: whoever reads it (UI, listeners) knows the
 * entry is written and must not audit again. `cause()` is the business exception, `action()` the audited action.
 * No bus ever strips it.
 */
final class AlreadyAudited extends \RuntimeException
{
    public function __construct(private readonly \Throwable $cause, private readonly AuditAction $action)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }

    public function cause(): \Throwable
    {
        return $this->cause;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }
}
