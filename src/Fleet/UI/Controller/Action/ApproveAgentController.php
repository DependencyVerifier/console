<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Agent\ApproveAgent;
use Fleet\Application\Query\Team\GetAgent;
use Fleet\UI\Controller\RouteCollection;
use Fleet\UI\Controller\Team\FleetPage;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The approval dialog: it shows the agent's fingerprint, to compare with the one the installation logged, before a
 * human approves it. ApproveAgent judges the scope, the permission and the quota.
 */
#[Route(path: '/teams/{teamId}/agents/{agentId}/approve', name: RouteCollection::AGENT_APPROVE->value, requirements: ['teamId' => Requirement::UUID, 'agentId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ApproveAgentController
{
    public function __construct(private QueryBus $queries, private FleetPage $page, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $agentId): Response
    {
        $agent = $this->queries->ask(new GetAgent($agentId));
        if (!$this->page->fleet($agent->teamId)->can('host.approve')) {
            throw new AccessDeniedException();
        }

        $form = $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => 'agent_approve:'.$agentId]);

        return $this->dialog->handle(
            $request,
            $form,
            'approve-agent-'.$agentId,
            'fleet.agents.approve',
            static fn (): ApproveAgent => new ApproveAgent($agentId),
            $this->redirect->withSuccessKey('fleet.agents.approved'),
            new RouteReference(RouteCollection::TEAM_AGENTS->prefixed(), ['teamId' => $teamId]),
            'fleet/dialog/approve_agent.html.twig',
            ['agent' => $agent],
        );
    }
}
