<?php

declare(strict_types=1);

namespace Notification\UI\DataTable;

use Notification\Application\Query\ListWebhooks;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

final class WebhooksTable implements DataTableTypeInterface
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
            ->setName('webhooks')
            ->add('name', TwigColumn::class, ['field' => 'name', 'label' => 'notification.field.name', 'orderable' => false, 'template' => 'notification/cells/text.html.twig'])
            ->add('url', TwigColumn::class, ['field' => 'url', 'label' => 'notification.field.url', 'orderable' => false, 'template' => 'notification/cells/url.html.twig'])
            ->add('last_call', TwigColumn::class, ['field' => 'lastCalledAt', 'label' => 'notification.field.last_call', 'orderable' => false, 'template' => 'notification/cells/webhook_status.html.twig'])
            ->add('actions', TwigColumn::class, ['field' => 'id', 'label' => 'admin.users.actions', 'orderable' => false, 'template' => 'notification/cells/webhook_actions.html.twig', 'className' => 'text-end'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListWebhooks => new ListWebhooks($teamId, $page)]);
    }
}
