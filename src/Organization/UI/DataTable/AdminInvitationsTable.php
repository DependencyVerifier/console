<?php

declare(strict_types=1);

namespace Organization\UI\DataTable;

use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Organization\Application\Query\Invitation\ListAllInvitations;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * Every pending invitation of the instance (/admin/users, under the accounts): ListAllInvitations is the judge (super
 * admin). Each row carries its team id for its actions.
 */
final class AdminInvitationsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $dataTable
            ->setName('invitations')
            ->add('email', TwigColumn::class, [...['label' => 'identity.field.email', 'orderable' => true, 'template' => 'organization/cells/invitation_email.html.twig'], 'globalSearchable' => false])
            ->add('team', TwigColumn::class, [...['label' => 'organization.invitations.target', 'orderable' => false, 'template' => 'organization/cells/invitation_target.html.twig'], 'globalSearchable' => false])
            ->add('role', TwigColumn::class, [...['label' => 'organization.field.role', 'orderable' => false, 'template' => 'organization/cells/invitation_role.html.twig'], 'globalSearchable' => false])
            ->add('expiresAt', TwigColumn::class, [...['label' => 'organization.field.expires', 'orderable' => false, 'template' => 'organization/cells/invitation_expires.html.twig'], 'globalSearchable' => false])
            ->add('actions', TwigColumn::class, [...['label' => 'admin.users.actions', 'orderable' => false, 'className' => 'text-end', 'template' => 'organization/cells/invitation_actions.html.twig'], 'globalSearchable' => false])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListAllInvitations => new ListAllInvitations($page)]);
    }
}
