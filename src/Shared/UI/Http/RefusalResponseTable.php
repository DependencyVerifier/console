<?php

declare(strict_types=1);

namespace Shared\UI\Http;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Security\Contended;
use Shared\Application\Security\RateLimiterStorageUnavailable;
use Shared\Application\Security\Throttled;
use Shared\Domain\BusinessRuleViolated;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single table from a refusal (the cause of AlreadyAudited) to its HTTP status and translation key, shared by
 * ActionResponder, the authentication pages and the exception listener.
 */
final class RefusalResponseTable
{
    public static function statusOf(\Throwable $cause): int
    {
        return match (true) {
            $cause instanceof BusinessRuleViolated => Response::HTTP_UNPROCESSABLE_ENTITY,
            $cause instanceof PermissionDenied => Response::HTTP_FORBIDDEN,
            $cause instanceof ResourceNotFound => Response::HTTP_NOT_FOUND,
            $cause instanceof Throttled, $cause instanceof Contended => Response::HTTP_TOO_MANY_REQUESTS,
            $cause instanceof UniqueConstraintViolationException => Response::HTTP_CONFLICT,
            $cause instanceof RateLimiterStorageUnavailable, $cause instanceof LockWaitTimeoutException => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    public static function messageKeyOf(\Throwable $cause): string
    {
        return match (true) {
            $cause instanceof BusinessRuleViolated => 'rule.'.$cause->ruleKey(),
            $cause instanceof PermissionDenied => 'refusal.forbidden',
            $cause instanceof ResourceNotFound => 'refusal.not_found',
            $cause instanceof Throttled, $cause instanceof Contended => 'refusal.throttled',
            $cause instanceof UniqueConstraintViolationException => 'refusal.conflict',
            $cause instanceof RateLimiterStorageUnavailable, $cause instanceof LockWaitTimeoutException => 'refusal.unavailable',
            default => 'refusal.error',
        };
    }
}
