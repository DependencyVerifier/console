<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Token;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;
use Shared\Application\Security\RevealsSecret;

/**
 * A new enrollment token, shown once (the UI generates it, only its hash is stored). Audited on the team, like every
 * creation; never the token itself.
 */
final readonly class CreateEnrollmentToken implements AuditableCommand, RevealsSecret
{
    public function __construct(
        public string $tokenId,
        public string $teamId,
        public bool $reusable,
        public ?string $environmentId,
        #[\SensitiveParameter] public string $plainToken,
        public int $validityDays = 0,
        public int $maxUses = 0,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.create_enrollment_token', ['token_id', 'kind']);
    }

    public function auditTarget(): Target
    {
        return Target::of('team', $this->teamId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['token_id' => $this->tokenId, 'kind' => $this->reusable ? 'reusable' : 'single']);
    }
}
