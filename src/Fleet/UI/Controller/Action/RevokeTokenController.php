<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Token\RevokeEnrollmentToken;
use Fleet\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A row menu action (Turbo Stream); the command judges the scope (404) and the permission (403) on the object's team.
 */
#[Route(path: '/teams/{teamId}/enrollment/{tokenId}/revoke', name: RouteCollection::TOKEN_REVOKE->value, requirements: ['teamId' => Requirement::UUID, 'tokenId' => Requirement::UUID], methods: ['POST'])]
final readonly class RevokeTokenController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $tokenId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'token_revoke:'.$tokenId,
            static fn () => new RevokeEnrollmentToken($tokenId),
            'fleet.tokens.revoked',
            new RouteReference(RouteCollection::TEAM_ENROLLMENT->prefixed(), ['teamId' => $teamId]),
        );
    }
}
