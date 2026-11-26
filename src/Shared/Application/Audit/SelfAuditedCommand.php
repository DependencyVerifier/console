<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

/**
 * Commands whose handler decides itself whether to write an entry, through CommandAudit: code verifications (TOTP,
 * email code, backup code, TOTP enrolment) write their single entry with the outcome of the attempt; machine
 * reactions (an agent's poll, the copy of a host label, the facts observed by an inventory) write none, or one for
 * the rare change worth keeping (a host identity promoted). AuditSuccessMiddleware skips them; AuditFailureMiddleware
 * still audits an unexpected technical exception.
 */
interface SelfAuditedCommand extends AuditableCommand
{
}
