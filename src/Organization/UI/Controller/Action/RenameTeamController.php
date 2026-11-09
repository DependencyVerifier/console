<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Team\RenameTeam;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\NameType;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/rename', name: RouteCollection::TEAM_RENAME->value, requirements: ['teamId' => Requirement::UUID], methods: ['POST'])]
final readonly class RenameTeamController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->action->handle(
            $request,
            'team_name',
            'team_rename:'.$teamId,
            static fn ($form) => new RenameTeam($teamId, FormData::string($form, 'name')),
            'organization.team.renamed',
            new RouteReference(RouteCollection::TEAM->prefixed(), ['teamId' => $teamId]), NameType::class, ['label_key' => 'organization.field.team_name'],
        );
    }
}
