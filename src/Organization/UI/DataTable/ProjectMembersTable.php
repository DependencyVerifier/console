<?php

declare(strict_types=1);

namespace Organization\UI\DataTable;

use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Organization\Application\Query\Project\ListProjectMembers;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped), with `projectId` in their
 * context for the actions.
 */
final class ProjectMembersTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $id = $options['projectId'] ?? null;
        if (!\is_string($id)) {
            throw new \InvalidArgumentException('Option "projectId" is required.');
        }

        $dataTable
            ->setName('project_members')
            ->add('email', TwigColumn::class, [...['label' => 'identity.field.email', 'orderable' => false, 'template' => 'organization/cells/member_email.html.twig'], 'globalSearchable' => false])
            ->add('role', TwigColumn::class, [...['label' => 'organization.field.role', 'orderable' => true, 'template' => 'organization/cells/project_member_role.html.twig'], 'globalSearchable' => false])
            ->add('actions', TwigColumn::class, [...['label' => 'admin.users.actions', 'orderable' => false, 'className' => 'text-end', 'template' => 'organization/cells/project_member_actions.html.twig'], 'globalSearchable' => false])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListProjectMembers => new ListProjectMembers($id, $page)]);
    }
}
