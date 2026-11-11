<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Audit;

use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\InventoryRun;
use Inventory\Infrastructure\Doctrine\InventoryManager;
use Shared\Application\Audit\AuditScopeResolver;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Team of Inventory's audit targets (an inventory, an application), read through the ORM identity map; an unknown
 * one (a refused manifest) has no scope.
 */
final readonly class InventoryScopeResolver implements AuditScopeResolver
{
    private const array CLASSES = ['inventory' => InventoryRun::class, 'application' => AppInstance::class];

    public function __construct(private InventoryManager $manager)
    {
    }

    public function supports(Target $target): bool
    {
        return isset(self::CLASSES[$target->type]) && Uuid::isValid($target->id());
    }

    public function resolve(Target $target): Scope
    {
        $object = $this->manager->get()->find(self::CLASSES[$target->type], Uuid::fromString($target->id()));

        return null === $object ? Scope::none() : Scope::team($object->teamId()->toRfc4122());
    }
}
