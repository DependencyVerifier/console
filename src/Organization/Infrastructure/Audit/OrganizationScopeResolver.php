<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Audit;

use Organization\Domain\Model\Environment;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\Project;
use Organization\Infrastructure\Doctrine\OrganizationManager;
use Shared\Application\Audit\AuditScopeResolver;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Team / project of Organization's audit targets, read through the ORM identity map (an entity not flushed yet is
 * found); an unknown target (rolled back creation) has no scope.
 */
final readonly class OrganizationScopeResolver implements AuditScopeResolver
{
    private const array TYPES = ['team', 'project', 'environment', 'invitation'];

    public function __construct(private OrganizationManager $manager)
    {
    }

    public function supports(Target $target): bool
    {
        return \in_array($target->type, self::TYPES, true) && Uuid::isValid($target->id());
    }

    public function resolve(Target $target): Scope
    {
        $manager = $this->manager->get();
        $id = Uuid::fromString($target->id());

        return match ($target->type) {
            'team' => Scope::team($target->id()),
            'project' => null !== ($project = $manager->find(Project::class, $id)) ? Scope::project($project->teamId()->toString(), $target->id()) : Scope::none(),
            'environment' => null !== ($environment = $manager->find(Environment::class, $id)) ? Scope::project($environment->project()->teamId()->toString(), $environment->project()->id()->toString()) : Scope::none(),
            'invitation' => null !== ($invitation = $manager->find(Invitation::class, $id))
                ? ($invitation->target()->isProject() ? Scope::project($invitation->target()->teamId()->toString(), $invitation->target()->projectId()->toString()) : Scope::team($invitation->target()->teamId()->toString()))
                : Scope::none(),
            default => Scope::none(),
        };
    }
}
