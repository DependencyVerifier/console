<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Rule\RemoveRule;
use Notification\UI\Controller\RouteCollection;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A row menu action (a rule of the fleet or of a project); the command judges on the rule's scope.
 */
#[Route(path: '/teams/{teamId}/notifications/rules/{ruleId}/remove', name: RouteCollection::RULE_REMOVE->value, requirements: ['teamId' => Requirement::UUID, 'ruleId' => Requirement::UUID], methods: ['POST'])]
final readonly class RemoveRuleController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, string $ruleId): Response
    {
        return $this->action->handle(
            $request,
            'action',
            'rule_remove:'.$ruleId,
            static fn () => new RemoveRule($ruleId),
            'notification.rules.removed',
            new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
