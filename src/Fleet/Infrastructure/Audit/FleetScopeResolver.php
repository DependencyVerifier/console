<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Audit;

use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\Host;
use Fleet\Infrastructure\Doctrine\FleetManager;
use Shared\Application\Audit\AuditScopeResolver;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Team of Fleet's audit targets, read through the ORM identity map; an unknown target (refused enrollment) has no
 * scope: the entry stays in the instance audit only.
 */
final readonly class FleetScopeResolver implements AuditScopeResolver
{
    private const array CLASSES = ['agent' => Agent::class, 'host' => Host::class, 'enrollment_token' => EnrollmentToken::class];

    public function __construct(private FleetManager $manager)
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
