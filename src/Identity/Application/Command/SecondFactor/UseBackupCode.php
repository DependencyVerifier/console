<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor;

use Identity\Application\SecondFactor\CodeVerification;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;

/**
 * Checks and consumes a backup code in one gesture, under the User lock.
 */
final readonly class UseBackupCode implements SelfAuditedCommand, CodeVerification
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter] public string $code,
        public string $attemptId,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('identity.use_backup_code');
    }

    public function auditTarget(): Target
    {
        return Target::of('user', $this->userId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
