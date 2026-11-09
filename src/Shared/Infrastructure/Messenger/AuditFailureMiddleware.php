<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Messenger;

use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditTrail;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Shared\Infrastructure\Audit\FailureWithScope;
use Shared\Infrastructure\Audit\RefusalClassifier;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Outside the transaction: after the rollback, writes the `failure` or `denied` entry on a clean connection, then
 * rethrows the business cause wrapped in AlreadyAudited (with the audited action). This is what the command bus
 * throws for any audited failure; nothing downstream audits it again.
 */
final readonly class AuditFailureMiddleware implements MiddlewareInterface
{
    public function __construct(private AuditTrail $auditTrail, private AuditEntryFactory $entries)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (AlreadyAudited $audited) {
            throw $audited;
        } catch (\Throwable $exception) {
            $command = $envelope->getMessage();
            if (!$command instanceof AuditableCommand) {
                throw $exception;
            }

            $cause = RefusalClassifier::causeOf($exception);
            if ($cause instanceof AlreadyAudited) {
                throw $cause; // A nested command already wrote its entry.
            }

            $this->auditTrail->record($this->entries->create(
                $command->auditAction(),
                $command->auditTarget(),
                RefusalClassifier::outcomeOf($cause),
                $command->auditContext(),
                $exception instanceof FailureWithScope ? $exception->scope : null,
            ));

            throw new AlreadyAudited($cause, $command->auditAction());
        }
    }
}
