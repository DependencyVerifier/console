<?php

declare(strict_types=1);

namespace Fleet\UI\DataTable;

use Fleet\Application\Query\Team\ListTeamAgents;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\DataTableView;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped).
 */
final class AgentsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $teamId = $options['teamId'] ?? null;
        if (!\is_string($teamId)) {
            throw new \InvalidArgumentException('Option "teamId" is required.');
        }

        $dataTable
            ->setName('agents')
            ->add('host', TwigColumn::class, ['field' => 'hostname', 'label' => 'fleet.field.host', 'orderable' => true, 'template' => 'fleet/cells/agent_host.html.twig'])
            ->add('status', TwigColumn::class, ['field' => 'status', 'label' => 'fleet.field.status', 'orderable' => true, 'template' => 'fleet/cells/agent_status.html.twig'])
            ->add('fingerprint', TwigColumn::class, ['field' => 'fingerprint', 'label' => 'fleet.field.fingerprint', 'orderable' => false, 'template' => 'fleet/cells/fingerprint.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('mode', TwigColumn::class, ['field' => 'mode', 'label' => 'fleet.field.mode', 'orderable' => false, 'template' => 'fleet/cells/agent_mode.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('version', TwigColumn::class, ['field' => 'version', 'label' => 'fleet.field.version', 'orderable' => true, 'template' => 'fleet/cells/text.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('last_seen', TwigColumn::class, ['field' => 'lastSeenAt', 'label' => 'fleet.field.last_seen', 'orderable' => true, 'template' => 'fleet/cells/time.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('actions', TwigColumn::class, ['field' => 'id', 'label' => 'admin.users.actions', 'orderable' => false, 'template' => 'fleet/cells/agent_actions.html.twig', 'className' => 'text-end'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListTeamAgents => new ListTeamAgents($teamId, $page)]);
    }
}
