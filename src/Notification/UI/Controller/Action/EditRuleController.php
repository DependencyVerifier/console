<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Action;

use Notification\Application\Command\Rule\RedefineRule;
use Notification\Application\Query\GetRule;
use Notification\UI\Controller\Page\NotificationPage;
use Notification\UI\Controller\RouteCollection;
use Notification\UI\Form\RuleForm;
use Notification\UI\Form\RuleType;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The "Edit" dialog of a rule (its scope never changes). GetRule judges who manages it.
 */
#[Route(path: '/teams/{teamId}/notifications/rules/{ruleId}/edit', name: RouteCollection::RULE_EDIT->value, requirements: ['teamId' => Requirement::UUID, 'ruleId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class EditRuleController
{
    public const string DIALOG_PREFIX = 'edit-rule-';

    public function __construct(private NotificationPage $page, private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $ruleId): Response
    {
        $rule = $this->queries->ask(new GetRule($ruleId));
        if ($rule->teamId !== $teamId) {
            throw new NotFoundHttpException();
        }

        $view = $this->page->view($teamId, $rule->projectId);
        $form = $this->forms->createNamed('rule', RuleType::class, RuleForm::data($rule->settings), [
            ...RuleForm::options($view, 'rule_edit:'.$ruleId),
            'silent_hours' => $rule->settings->silentHours,
        ]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG_PREFIX.$ruleId,
            'action.save',
            static fn (): RedefineRule => new RedefineRule($ruleId, RuleForm::settings($form)),
            $this->redirect->withSuccessKey('notification.rules.updated'),
            null === $rule->projectId
                ? new RouteReference(RouteCollection::TEAM_NOTIFICATIONS->prefixed(), ['teamId' => $teamId])
                : new RouteReference(RouteCollection::PROJECT_NOTIFICATIONS->prefixed(), ['teamId' => $teamId, 'projectId' => $rule->projectId]),
        );
    }
}
