<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Rule\DefineRule;
use Notification\UI\Controller\Page\NotificationPage;
use Notification\UI\Controller\RouteCollection;
use Notification\UI\Form\RuleForm;
use Notification\UI\Form\RuleType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * The "New rule" dialog, for the fleet of a team or for a project. DefineRule judges the permission and the values.
 */
#[Route(path: '/teams/{teamId}/notifications/rules/new', name: RouteCollection::TEAM_RULE_CREATE->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
#[Route(path: '/teams/{teamId}/projects/{projectId}/notifications/rules/new', name: RouteCollection::PROJECT_RULE_CREATE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class CreateRuleController
{
    public const string DIALOG = 'create-rule';

    public function __construct(private NotificationPage $page, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, ?string $projectId = null): Response
    {
        $view = $this->page->view($teamId, $projectId);
        if (!$view->manages) {
            throw new AccessDeniedException();
        }

        $form = $this->forms->createNamed('rule', RuleType::class, RuleForm::blank(), RuleForm::options($view, 'rule_create:'.$teamId.':'.($projectId ?? 'fleet')));

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'notification.rules.create',
            static fn (): DefineRule => new DefineRule(Uuid::v7()->toRfc4122(), $teamId, $projectId, RuleForm::settings($form)),
            $this->redirect->withSuccessKey('notification.rules.created'),
            null === $projectId
                ? new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId])
                : new RouteReference(RouteCollection::PROJECT_NOTIFICATIONS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
