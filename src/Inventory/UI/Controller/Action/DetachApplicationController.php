<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Action;

use Inventory\Application\Command\Application\DetachApplication;
use Inventory\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/applications/{appId}/detach', name: RouteCollection::APPLICATION_DETACH->value, requirements: ['teamId' => Requirement::UUID, 'appId' => Requirement::UUID], methods: ['POST'])]
final readonly class DetachApplicationController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $appId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'application_detach:'.$appId,
            static fn () => new DetachApplication($appId),
            'inventory.applications.detached',
            new RouteReference(RouteCollection::APPLICATION->prefixed(), ['teamId' => $teamId, 'appId' => $appId]),
        );
    }
}
