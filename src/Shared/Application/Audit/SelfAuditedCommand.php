<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

/**
 * Code verifications (TOTP, email code, backup code, TOTP enrolment): their handler writes the single entry itself,
 * with the outcome read from the verification attempt. AuditSuccessMiddleware skips them; AuditFailureMiddleware
 * still audits an unexpected technical exception.
 */
interface SelfAuditedCommand extends AuditableCommand
{
}
