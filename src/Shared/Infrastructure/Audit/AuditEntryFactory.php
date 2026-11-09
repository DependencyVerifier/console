<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Audit;

use Psr\Clock\ClockInterface;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditEntry;
use Shared\Application\Audit\AuditScopeResolver;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Shared\Application\Security\CurrentActor;
use Shared\Application\Security\RequestContextProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Builds audit entries with the current actor, request context and clock, and resolves the scope of a target
 * through the resolvers each context provides.
 */
final readonly class AuditEntryFactory
{
    /**
     * @param iterable<AuditScopeResolver> $scopeResolvers
     */
    public function __construct(
        private CurrentActor $currentActor,
        private RequestContextProvider $requestContext,
        private ClockInterface $clock,
        #[AutowireIterator(AuditScopeResolver::class)]
        private iterable $scopeResolvers,
    ) {
    }

    public function scopeOf(Target $target): Scope
    {
        if ($target->isNone()) {
            return Scope::none();
        }

        foreach ($this->scopeResolvers as $resolver) {
            if ($resolver->supports($target)) {
                return $resolver->resolve($target);
            }
        }

        return Scope::none();
    }

    public function create(AuditAction $action, Target $target, Outcome $outcome, AuditContext $context, ?Scope $scope = null): AuditEntry
    {
        return new AuditEntry(
            $this->currentActor->actor(),
            $action,
            $target,
            $scope ?? $this->scopeOf($target),
            $outcome,
            $this->requestContext->current(),
            $context,
            $this->clock->now(),
        );
    }
}
