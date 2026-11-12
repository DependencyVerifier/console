<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Action;

use Exposure\Application\Command\Risk\RevokeRiskAcceptance;
use Exposure\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/findings/{findingId}/revoke', name: RouteCollection::FINDING_REVOKE->value, requirements: ['teamId' => Requirement::UUID, 'findingId' => Requirement::UUID], methods: ['POST'])]
final readonly class RevokeRiskAcceptanceController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $findingId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'finding_revoke:'.$findingId,
            static fn () => new RevokeRiskAcceptance($findingId),
            'exposure.risk.revoked',
            new RouteReference(RouteCollection::FINDING->prefixed(), ['teamId' => $teamId, 'findingId' => $findingId]),
        );
    }
}
