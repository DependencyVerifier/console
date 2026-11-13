<?php

declare(strict_types=1);

namespace Notification\UI\Controller\Page;

use Notification\Application\Query\GetNotifications;
use Notification\Application\Query\View\NotificationsView;
use Notification\UI\Controller\RouteCollection;
use Notification\UI\Form\FrequencyType;
use Omines\DataTablesBundle\DataTable;
use Organization\UI\Controller\Project\ProjectPage;
use Organization\UI\Controller\Team\TeamPage;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * The Notifications section of a team (its webhooks, the rules of its fleet) and the Notifications tab of a project
 * (its rules, its recipients), each with one's own subscription. GetNotifications judges the scope (404) and the fleet
 * (403 for a TEAM_GUEST); the actions cells read its view from the request (also during a DataTable callback).
 */
final readonly class NotificationPage
{
    public const string SECTION = 'notifications';
    public const string ATTRIBUTE = '_notifications';

    public function __construct(
        private TeamPage $teams,
        private ProjectPage $projects,
        private QueryBus $queries,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
        private RequestStack $requests,
    ) {
    }

    public function view(string $teamId, ?string $projectId): NotificationsView
    {
        $view = $this->queries->ask(new GetNotifications($teamId, $projectId));
        $this->requests->getCurrentRequest()?->attributes->set(self::ATTRIBUTE, $view);

        return $view;
    }

    /**
     * @param array<string, DataTable> $tables
     */
    public function team(string $teamId, array $tables): Response
    {
        $team = $this->teams->team($teamId);
        $view = $this->view($teamId, null);
        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        return new Response($this->twig->render('notification/team/notifications.html.twig', [
            'team' => $team,
            'notifications' => $view,
            'subscription_form' => $this->subscriptionForm($view),
            'tab' => self::SECTION,
            'tabs' => TeamPage::tabsOf($team),
            'tables' => $tables,
            'nav_active' => 'team_'.self::SECTION,
            'current_team' => $team->id,
        ]));
    }

    /**
     * @param array<string, DataTable> $tables
     */
    public function project(string $teamId, string $projectId, array $tables): Response
    {
        $project = $this->projects->project($teamId, $projectId);
        $view = $this->view($teamId, $projectId);

        return $this->projects->render($project, ProjectPage::NOTIFICATIONS, $tables, [
            'notifications' => $view,
            'subscription_form' => $this->subscriptionForm($view),
        ], 'notification/project/notifications.html.twig');
    }

    private function subscriptionForm(NotificationsView $view): ?FormView
    {
        if (!$view->subscribes) {
            return null;
        }

        $action = null === $view->projectId
            ? $this->urls->generate(RouteCollection::TEAM_SUBSCRIBE->prefixed(), ['teamId' => $view->teamId])
            : $this->urls->generate(RouteCollection::PROJECT_SUBSCRIBE->prefixed(), ['teamId' => $view->teamId, 'projectId' => $view->projectId]);

        return $this->forms->createNamed('subscription', FrequencyType::class, ['frequency' => $view->myFrequency ?? 'off'], [
            'csrf_token_id' => 'notification_subscribe:'.$view->teamId.':'.($view->projectId ?? 'fleet'),
            'action' => $action,
        ])->createView();
    }
}
