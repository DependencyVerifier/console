<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Messenger;

use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Target;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Shared\Infrastructure\Audit\RefusalClassifier;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

use function Symfony\Component\String\u;

/**
 * Only custom middleware of query.bus: a query handler is the single judge of scope and permission, and its refusals
 * (PermissionDenied, ResourceNotFound) are audited here as `denied`, once. Successful reads are not audited.
 */
final readonly class AuditDeniedQueryMiddleware implements MiddlewareInterface
{
    public function __construct(private AuditTrail $auditTrail, private AuditEntryFactory $entries)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious() ?? $exception;
            if (!$cause instanceof PermissionDenied && !$cause instanceof ResourceNotFound) {
                throw $exception;
            }

            $action = AuditAction::named('query.denied', ['query']);
            $this->auditTrail->record($this->entries->create(
                $action,
                Target::none(),
                RefusalClassifier::outcomeOf($cause),
                AuditContext::for($action, ['query' => u($envelope->getMessage()::class)->afterLast('\\')->toString()]),
            ));

            throw new AlreadyAudited($cause, $action);
        }
    }
}
