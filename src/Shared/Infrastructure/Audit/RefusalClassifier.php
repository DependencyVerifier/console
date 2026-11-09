<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Audit;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shared\Application\Audit\Outcome;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Security\Contended;
use Shared\Application\Security\Throttled;
use Shared\Domain\BusinessRuleViolated;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Maps the business cause of a failed command to its audit outcome. Single table, shared by the audit middlewares.
 */
final class RefusalClassifier
{
    public static function outcomeOf(\Throwable $cause): Outcome
    {
        return match (true) {
            $cause instanceof PermissionDenied => Outcome::denied('permission'),
            $cause instanceof ResourceNotFound => Outcome::denied('not_found'),
            $cause instanceof BusinessRuleViolated => Outcome::denied('rule:'.$cause->ruleKey()),
            $cause instanceof Throttled => Outcome::denied('throttled'),
            $cause instanceof Contended => Outcome::denied('lock_contention'),
            $cause instanceof UniqueConstraintViolationException => Outcome::failure('conflict'),
            $cause instanceof LockWaitTimeoutException => Outcome::failure('technical'),
            default => Outcome::failure('technical'),
        };
    }

    /**
     * The business exception behind Messenger's wrapper and our scope envelope.
     */
    public static function causeOf(\Throwable $exception): \Throwable
    {
        if ($exception instanceof FailureWithScope) {
            $exception = $exception->getPrevious() ?? $exception;
        }

        if ($exception instanceof HandlerFailedException) {
            return $exception->getPrevious() ?? $exception;
        }

        return $exception;
    }
}
