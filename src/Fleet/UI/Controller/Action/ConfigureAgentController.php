<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Order\ConfigureAgent;
use Fleet\Application\Query\Team\GetAgent;
use Fleet\UI\Controller\RouteCollection;
use Fleet\UI\Controller\Team\FleetPage;
use Fleet\UI\Form\AgentConfigType;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
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
 * The "Configure" dialog of an agent, filled with its current settings. ConfigureAgent judges the permission, the
 * agent state and the team's minimum interval.
 */
#[Route(path: '/teams/{teamId}/agents/{agentId}/configure', name: RouteCollection::AGENT_CONFIGURE->value, requirements: ['teamId' => Requirement::UUID, 'agentId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class ConfigureAgentController
{
    public const int DEFAULT_INTERVAL = 360;

    public function __construct(private QueryBus $queries, private FleetPage $page, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $agentId): Response
    {
        $agent = $this->queries->ask(new GetAgent($agentId));
        if (!$this->page->fleet($agent->teamId)->can('host.order')) {
            throw new AccessDeniedException();
        }

        $current = $agent->desiredConfig ?? [];
        $interval = $current['scan_interval_minutes'] ?? self::DEFAULT_INTERVAL;
        $form = $this->forms->createNamed('agent_config', AgentConfigType::class, null, [
            'csrf_token_id' => 'agent_configure:'.$agentId,
            'interval' => \is_int($interval) ? $interval : self::DEFAULT_INTERVAL,
            'paths' => $current,
        ]);

        return $this->dialog->handle(
            $request,
            $form,
            'configure-agent',
            'fleet.configure.submit',
            static fn (): ConfigureAgent => new ConfigureAgent(
                Uuid::v7()->toRfc4122(),
                $agentId,
                FormData::int($form, 'scan_interval_minutes'),
                AgentConfigType::lines(FormData::nullableString($form, 'discovery_roots')),
                AgentConfigType::lines(FormData::nullableString($form, 'declared_paths')),
                AgentConfigType::lines(FormData::nullableString($form, 'exclusions')),
            ),
            $this->redirect->withSuccessKey('fleet.configure.done'),
            new RouteReference(RouteCollection::AGENT->prefixed(), ['teamId' => $teamId, 'agentId' => $agentId]),
        );
    }
}
