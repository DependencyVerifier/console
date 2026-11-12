<?php

declare(strict_types=1);

namespace Exposure\Infrastructure\Audit;

use Exposure\Domain\Model\Finding;
use Exposure\Infrastructure\Doctrine\ExposureManager;
use Shared\Application\Audit\AuditScopeResolver;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Team of a finding (the target of a risk decision), read through the ORM identity map.
 */
final readonly class ExposureScopeResolver implements AuditScopeResolver
{
    public function __construct(private ExposureManager $manager)
    {
    }

    public function supports(Target $target): bool
    {
        return 'finding' === $target->type && Uuid::isValid($target->id());
    }

    public function resolve(Target $target): Scope
    {
        $finding = $this->manager->get()->find(Finding::class, Uuid::fromString($target->id()));

        return null === $finding ? Scope::none() : Scope::team($finding->teamId()->toRfc4122());
    }
}
