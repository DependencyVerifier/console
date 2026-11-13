<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Subscription\Subscribe;
use Notification\UI\Controller\RouteCollection;
use Notification\UI\Form\FrequencyType;
use Organization\UI\Controller\Action\OrganizationAction;
use Shared\UI\Action\FormData;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * One's own subscription to the fleet of a team or to a project, changed from its select.
 */
#[Route(path: '/teams/{teamId}/notifications/subscription', name: RouteCollection::TEAM_SUBSCRIBE->value, requirements: ['teamId' => Requirement::UUID], methods: ['POST'])]
#[Route(path: '/teams/{teamId}/projects/{projectId}/notifications/subscription', name: RouteCollection::PROJECT_SUBSCRIBE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['POST'])]
final readonly class SubscribeController
{
    public function __construct(private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $teamId, ?string $projectId = null): Response
    {
        return $this->action->handle(
            $request,
            'subscription',
            'notification_subscribe:'.$teamId.':'.($projectId ?? 'fleet'),
            static fn (FormInterface $form): Subscribe => new Subscribe($teamId, $projectId, FormData::string($form, 'frequency')),
            'notification.subscription.saved',
            null === $projectId
                ? new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId])
                : new RouteReference(RouteCollection::PROJECT_NOTIFICATIONS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
            FrequencyType::class,
        );
    }
}
