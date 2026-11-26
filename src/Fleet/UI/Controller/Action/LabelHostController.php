<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Action;

use Fleet\Application\Command\Host\LabelHost;
use Fleet\Application\Query\Team\GetHostLabel;
use Fleet\UI\Controller\RouteCollection;
use Fleet\UI\Form\AgentConfigType;
use Fleet\UI\Form\HostLabelType;
use Inventory\UI\Controller\RouteCollection as InventoryRoutes;
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

use function Symfony\Component\String\u;

/**
 * The "Edit" dialog of a host: its display name and IP addresses. GetHostLabel and LabelHost judge the scope (404)
 * and host.edit (403).
 */
#[Route(path: '/teams/{teamId}/hosts/{hostId}/label', name: RouteCollection::HOST_LABEL->value, requirements: ['teamId' => Requirement::UUID, 'hostId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class LabelHostController
{
    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $hostId): Response
    {
        $label = $this->queries->ask(new GetHostLabel($teamId, $hostId));
        $form = $this->forms->createNamed('host', HostLabelType::class, ['display_name' => $label->displayName, 'addresses' => u("\n")->join($label->addresses)->toString()], [
            'csrf_token_id' => 'host_label:'.$hostId,
            'hostname' => $label->hostname,
        ]);

        return $this->dialog->handle(
            $request,
            $form,
            'label-host-'.$hostId,
            'fleet.hosts.label',
            static fn (): LabelHost => new LabelHost($hostId, FormData::nullableString($form, 'display_name'), AgentConfigType::lines(u(FormData::nullableString($form, 'addresses') ?? '')->replace(',', "\n")->toString())),
            $this->redirect->withSuccessKey('fleet.hosts.labelled'),
            new RouteReference(InventoryRoutes::HOST->prefixed(), ['teamId' => $teamId, 'hostId' => $hostId]),
        );
    }
}
