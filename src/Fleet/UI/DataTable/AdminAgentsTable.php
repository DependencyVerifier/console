<?php

declare(strict_types=1);

namespace Fleet\UI\DataTable;

use Fleet\Application\Query\Admin\ListAllAgents;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped).
 */
final class AdminAgentsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $dataTable
            ->setName('agents')
            ->add('team', TwigColumn::class, ['field' => 'teamName', 'label' => 'fleet.field.team', 'orderable' => false, 'template' => 'fleet/cells/agent_team.html.twig'])
            ->add('host', TwigColumn::class, ['field' => 'hostname', 'label' => 'fleet.field.host', 'orderable' => true, 'template' => 'fleet/cells/text.html.twig'])
            ->add('status', TwigColumn::class, ['field' => 'status', 'label' => 'fleet.field.status', 'orderable' => true, 'template' => 'fleet/cells/agent_status.html.twig'])
            ->add('fingerprint', TwigColumn::class, ['field' => 'fingerprint', 'label' => 'fleet.field.fingerprint', 'orderable' => false, 'template' => 'fleet/cells/fingerprint.html.twig'])
            ->add('version', TwigColumn::class, ['field' => 'version', 'label' => 'fleet.field.version', 'orderable' => true, 'template' => 'fleet/cells/text.html.twig'])
            ->add('last_seen', TwigColumn::class, ['field' => 'lastSeenAt', 'label' => 'fleet.field.last_seen', 'orderable' => true, 'template' => 'fleet/cells/time.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListAllAgents => new ListAllAgents($page)]);
    }
}
