<?php

declare(strict_types=1);

namespace Notification\UI\DataTable;

use Notification\Application\Query\ListRecipients;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

final class RecipientsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $teamId = $options['teamId'] ?? null;
        $projectId = $options['projectId'] ?? null;
        if (!\is_string($teamId) || !\is_string($projectId)) {
            throw new \InvalidArgumentException('Options "teamId" and "projectId" are required.');
        }

        $dataTable
            ->setName('recipients')
            ->add('email', TwigColumn::class, ['field' => 'email', 'label' => 'notification.field.email', 'orderable' => false, 'template' => 'notification/cells/text.html.twig'])
            ->add('frequency', TwigColumn::class, ['field' => 'frequency', 'label' => 'notification.field.frequency', 'orderable' => false, 'template' => 'notification/cells/frequency.html.twig'])
            ->add('state', TwigColumn::class, ['field' => 'state', 'label' => 'notification.field.state', 'orderable' => false, 'template' => 'notification/cells/recipient_state.html.twig'])
            ->add('actions', TwigColumn::class, ['field' => 'id', 'label' => 'admin.users.actions', 'orderable' => false, 'template' => 'notification/cells/recipient_actions.html.twig', 'className' => 'text-end'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListRecipients => new ListRecipients($teamId, $projectId, $page)]);
    }
}
