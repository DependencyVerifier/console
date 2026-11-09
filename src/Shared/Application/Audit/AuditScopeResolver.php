<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Resolves the team / project of a target. Each context implements it for its own targets, so the Audit context
 * never imports another one. Implementations read the ORM identity map (an entity not flushed yet is found).
 */
#[AutoconfigureTag]
interface AuditScopeResolver
{
    public function supports(Target $target): bool;

    public function resolve(Target $target): Scope;
}
