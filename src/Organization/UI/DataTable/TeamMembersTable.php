<?php

declare(strict_types=1);

namespace Organization\UI\DataTable;

use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Organization\Application\Query\Team\ListTeamMembers;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\DataTableView;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped), with `teamId` in their
 * context for the actions.
 */
final class TeamMembersTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $id = $options['teamId'] ?? null;
        if (!\is_string($id)) {
            throw new \InvalidArgumentException('Option "teamId" is required.');
        }

        $dataTable
            ->setName('team_members')
            ->add('email', TwigColumn::class, [...['label' => 'identity.field.email', 'orderable' => false, 'template' => 'organization/cells/member_email.html.twig'], 'globalSearchable' => false])
            ->add('role', TwigColumn::class, [...['label' => 'organization.field.role', 'orderable' => true, 'template' => 'organization/cells/team_member_role.html.twig'], 'globalSearchable' => false])
            ->add('projects', TwigColumn::class, [...['label' => 'organization.field.projects', 'orderable' => false, 'template' => 'organization/cells/member_projects.html.twig'], 'globalSearchable' => false, 'className' => DataTableView::SECONDARY])
            ->add('actions', TwigColumn::class, [...['label' => 'admin.users.actions', 'orderable' => false, 'className' => 'text-end', 'template' => 'organization/cells/team_member_actions.html.twig'], 'globalSearchable' => false])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListTeamMembers => new ListTeamMembers($id, $page)]);
    }
}
