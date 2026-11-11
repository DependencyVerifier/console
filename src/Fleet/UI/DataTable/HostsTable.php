<?php

declare(strict_types=1);

namespace Fleet\UI\DataTable;

use Fleet\Application\Query\Team\ListTeamHosts;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped).
 */
final class HostsTable implements DataTableTypeInterface
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
            ->setName('hosts')
            ->add('hostname', TwigColumn::class, ['field' => 'hostname', 'label' => 'fleet.field.hostname', 'orderable' => true, 'template' => 'fleet/cells/host_name.html.twig'])
            ->add('os', TwigColumn::class, ['field' => 'os', 'label' => 'fleet.field.os', 'orderable' => false, 'template' => 'fleet/cells/text.html.twig'])
            ->add('agents', TwigColumn::class, ['field' => 'liveAgents', 'label' => 'fleet.field.agents', 'orderable' => false, 'template' => 'fleet/cells/number.html.twig'])
            ->add('last_seen', TwigColumn::class, ['field' => 'lastSeenAt', 'label' => 'fleet.field.last_seen', 'orderable' => true, 'template' => 'fleet/cells/time.html.twig'])
            ->add('actions', TwigColumn::class, ['field' => 'id', 'label' => 'admin.users.actions', 'orderable' => false, 'template' => 'fleet/cells/host_actions.html.twig', 'className' => 'text-end'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListTeamHosts => new ListTeamHosts($teamId, $page)]);
    }
}
