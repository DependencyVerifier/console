<?php

declare(strict_types=1);

namespace Organization\UI\DataTable;

use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Organization\Application\Query\Team\ListInvitations;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped), with `teamId` in their
 * context for the actions.
 */
final class InvitationsTable implements DataTableTypeInterface
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

        // Set: the pending invitations of that project only (its Members section), else those of the whole team.
        $projectId = $options['projectId'] ?? null;
        $projectId = \is_string($projectId) ? $projectId : null;

        $dataTable
            ->setName('invitations')
            ->add('email', TwigColumn::class, [...['label' => 'identity.field.email', 'orderable' => true, 'template' => 'organization/cells/invitation_email.html.twig'], 'globalSearchable' => false])
            ->add('role', TwigColumn::class, [...['label' => 'organization.field.role', 'orderable' => false, 'template' => 'organization/cells/invitation_role.html.twig'], 'globalSearchable' => false])
            ->add('expiresAt', TwigColumn::class, [...['label' => 'organization.field.expires', 'orderable' => false, 'template' => 'organization/cells/invitation_expires.html.twig'], 'globalSearchable' => false])
            ->add('actions', TwigColumn::class, [...['label' => 'admin.users.actions', 'orderable' => false, 'className' => 'text-end', 'template' => 'organization/cells/invitation_actions.html.twig'], 'globalSearchable' => false])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListInvitations => new ListInvitations($id, $page, $projectId)]);
    }
}
