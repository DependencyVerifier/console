<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Messenger;

use Psr\Log\LoggerInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\Target;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Shared\Infrastructure\Doctrine\DomainEventCollector;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Outermost: once the command is committed, publishes the collected events on event.bus. The collector is emptied
 * before publishing, so a nested command never republishes the events of the outer one.
 *
 * A failing reaction never requalifies the committed command: it is logged and audited as `event.handler_failed`
 * with class names only (event, innermost handler, exception, failed action), never the event object, the envelope
 * or the exception message, which may hold an invitation token in memory.
 */
final readonly class PublishDomainEventsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DomainEventCollector $collector,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private AuditTrail $auditTrail,
        private AuditEntryFactory $entries,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $this->collector->release();

            throw $exception;
        }

        foreach ($this->collector->release() as $event) {
            try {
                $this->eventBus->dispatch($event);
            } catch (\Throwable $failure) {
                $this->reportFailedReaction($event::class, $failure);
            }
        }

        return $envelope;
    }

    private function reportFailedReaction(string $eventClass, \Throwable $failure): void
    {
        [$handler, $cause] = $this->innermost($failure);
        $failedAction = $cause instanceof AlreadyAudited ? $cause->action()->name : '';
        $causeClass = $cause instanceof AlreadyAudited ? $cause->cause()::class : $cause::class;

        $this->logger->error('A reaction to a domain event failed.', [
            'event' => $eventClass,
            'handler' => $handler,
            'exception' => $causeClass,
            'failed_action' => $failedAction,
        ]);

        $action = AuditAction::named('event.handler_failed', ['event', 'handler', 'exception', 'failed_action']);
        $this->auditTrail->record($this->entries->create(
            $action,
            Target::none(),
            Outcome::failure('event_handler'),
            AuditContext::for($action, [
                'event' => $eventClass,
                'handler' => $handler,
                'exception' => $causeClass,
                'failed_action' => $failedAction,
            ]),
        ));
    }

    /**
     * @return array{string, \Throwable}
     */
    private function innermost(\Throwable $failure): array
    {
        $handler = 'unknown';
        while ($failure instanceof HandlerFailedException && [] !== $failure->getWrappedExceptions()) {
            $wrapped = $failure->getWrappedExceptions();
            $handler = (string) array_key_first($wrapped);
            $failure = array_first($wrapped);
        }

        return [$handler, $failure];
    }
}
