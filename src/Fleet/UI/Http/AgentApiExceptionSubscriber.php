<?php

declare(strict_types=1);

namespace Fleet\UI\Http;

use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Psr\Clock\ClockInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Security\Throttled;
use Shared\Domain\BusinessRuleViolated;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\String\u;

/**
 * Under /agent/v1 every error is a problem document (protocol §9), never an HTML page nor a redirect: business
 * refusals by their rule key, throttling as 429 + Retry-After, routing errors as not_found / invalid_payload, the rest
 * as a bare 500 (no detail).
 */
final readonly class AgentApiExceptionSubscriber
{
    public function __construct(private ClockInterface $clock)
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function onException(ExceptionEvent $event): void
    {
        if (!u($event->getRequest()->getPathInfo())->startsWith('/agent/v1/')) {
            return;
        }

        $now = $this->clock->now();
        $exception = $event->getThrowable();
        $cause = $exception instanceof AlreadyAudited ? $exception->cause() : $exception;
        $event->setResponse(match (true) {
            $cause instanceof ProtocolRefusal => ProblemResponse::of($cause->problem, $now, $cause->detail),
            $cause instanceof BusinessRuleViolated => ProblemResponse::of(Problem::ofRule($cause->ruleKey()), $now),
            $cause instanceof Throttled => ProblemResponse::of(Problem::RateLimited, $now, retryAfter: $cause->retryAfter),
            $cause instanceof HttpExceptionInterface && 404 === $cause->getStatusCode() => ProblemResponse::of(Problem::NotFound, $now),
            $cause instanceof HttpExceptionInterface && $cause->getStatusCode() < 500 => ProblemResponse::of(Problem::InvalidPayload, $now),
            default => ProblemResponse::generic(500, $now),
        });
    }
}
