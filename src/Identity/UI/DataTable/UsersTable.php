<?php

declare(strict_types=1);

namespace Identity\UI\DataTable;

use Identity\Application\Query\User\ListUsers;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * Accounts of the instance (ListUsers decides who may list them). Cells are Twig templates (autoescaped).
 */
final class UsersTable implements DataTableTypeInterface
{
    public function configure(DataTable $dataTable, array $options): void
    {
        $dataTable
            ->setName('users')
            ->add('email', TwigColumn::class, ['label' => 'identity.field.email', 'orderable' => true, 'template' => 'identity/admin/cells/email.html.twig'])
            ->add('active', TwigColumn::class, ['label' => 'admin.users.status', 'orderable' => false, 'template' => 'identity/admin/cells/status.html.twig'])
            ->add('totpActive', TwigColumn::class, ['label' => 'admin.users.second_factor', 'orderable' => false, 'template' => 'identity/admin/cells/second_factor.html.twig'])
            ->add('actions', TwigColumn::class, ['label' => 'admin.users.actions', 'orderable' => false, 'className' => 'text-end', 'template' => 'identity/admin/cells/actions.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListUsers => new ListUsers($page)]);
    }
}
