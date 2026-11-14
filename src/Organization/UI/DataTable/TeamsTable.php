<?php

declare(strict_types=1);

namespace Organization\UI\DataTable;

use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Organization\Application\Query\Team\ListTeams;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\DataTableView;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * Every team of the instance (super admin): ListTeams returns them all for the super admin only.
 */
final class TeamsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $dataTable
            ->setName('teams')
            ->add('name', TwigColumn::class, ['label' => 'organization.field.team_name', 'orderable' => true, 'template' => 'organization/cells/team_name.html.twig'])
            ->add('members', TwigColumn::class, ['field' => 'members', 'label' => 'organization.field.members', 'orderable' => false, 'template' => 'organization/cells/number.html.twig'])
            ->add('projects', TwigColumn::class, ['field' => 'projects', 'label' => 'organization.field.projects', 'orderable' => false, 'template' => 'organization/cells/number.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('actions', TwigColumn::class, ['label' => 'admin.users.actions', 'orderable' => false, 'className' => 'text-end', 'template' => 'organization/cells/team_actions.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListTeams => new ListTeams($page)]);
    }
}
