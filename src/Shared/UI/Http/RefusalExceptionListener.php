<?php

declare(strict_types=1);

namespace Shared\UI\Http;

use Psr\Log\LoggerInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\Target;
use Shared\Application\Security\RateLimiterStorageUnavailable;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\LogoutException;

/**
 * Before the firewall's exception listener (priority 1):
 * - an audited refusal (AlreadyAudited) becomes its HTTP status, never audited again;
 * - a limiter storage failure outside the bus (login throttling) is audited once and answered 503;
 * - a session lock or statement timeout is answered 503 (no anonymous fallback session);
 * - a logout with an invalid CSRF token goes to CsrfRejection;
 * - a firewall / voter refusal of an authenticated user is audited once (`denied`), then rendered as usual.
 */
#[AsEventListener(KernelEvents::EXCEPTION, priority: 2)]
final readonly class RefusalExceptionListener
{
    public function __construct(
        private AuditTrail $auditTrail,
        private AuditEntryFactory $entries,
        private CsrfRejection $csrfRejection,
        private TokenStorageInterface $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception instanceof AlreadyAudited) {
            $event->setThrowable(new HttpException(RefusalResponseTable::statusOf($exception->cause()), '', $exception));

            return;
        }

        if ($exception instanceof LogoutException || $exception->getPrevious() instanceof LogoutException) {
            $event->setResponse($this->csrfRejection->reject('logout'));

            return;
        }

        if ($exception instanceof RateLimiterStorageUnavailable) {
            $this->record('security.throttle_storage_failed', Outcome::failure('throttle_storage_failed'));
            $event->setThrowable(new HttpException(503, '', $exception));

            return;
        }

        if ($exception instanceof \PDOException && \in_array((string) $exception->getCode(), ['55P03', '57014'], true)) {
            $this->logger->error('Session storage timeout.', ['exception_class' => $exception::class]);
            $event->setThrowable(new HttpException(503, '', $exception));

            return;
        }

        if ($exception instanceof AccessDeniedException && null !== $this->tokens->getToken()?->getUser()) {
            $this->record('security.access_denied', Outcome::denied('permission'));
        }
    }

    private function record(string $action, Outcome $outcome): void
    {
        try {
            $this->auditTrail->record($this->entries->create(AuditAction::named($action), Target::none(), $outcome, AuditContext::empty()));
        } catch (\Throwable $failure) {
            $this->logger->error('A refusal could not be audited.', ['exception_class' => $failure::class]);
        }
    }
}
