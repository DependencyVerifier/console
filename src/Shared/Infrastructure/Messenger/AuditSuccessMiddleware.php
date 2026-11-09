<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Messenger;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Innermost: writes the `success` entry inside the command transaction, atomically with its effect.
 * Self-audited commands (code verifications) write their own entry.
 */
final readonly class AuditSuccessMiddleware implements MiddlewareInterface
{
    public function __construct(private AuditTrail $auditTrail, private AuditEntryFactory $entries)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $command = $envelope->getMessage();
        if (!$command instanceof AuditableCommand) {
            throw new \LogicException(\sprintf('Command "%s" must implement %s.', $command::class, AuditableCommand::class));
        }

        $envelope = $stack->next()->handle($envelope, $stack);

        if (!$command instanceof SelfAuditedCommand) {
            $this->auditTrail->record($this->entries->create(
                $command->auditAction(),
                $command->auditTarget(),
                Outcome::success(),
                $command->auditContext(),
            ));
        }

        return $envelope;
    }
}
