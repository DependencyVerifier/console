<?php

declare(strict_types=1);

namespace Fleet\UI\DataTable;

use Fleet\Application\Query\Team\ListAgentOrders;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The orders of one agent, newest first (ListAgentOrders judges the access).
 */
final class OrdersTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $agentId = $options['agentId'] ?? null;
        if (!\is_string($agentId)) {
            throw new \InvalidArgumentException('Option "agentId" is required.');
        }

        $dataTable
            ->setName('orders')
            ->add('type', TwigColumn::class, ['field' => 'type', 'label' => 'fleet.field.order', 'orderable' => false, 'template' => 'fleet/cells/order_type.html.twig'])
            ->add('status', TwigColumn::class, ['field' => 'status', 'label' => 'fleet.field.status', 'orderable' => false, 'template' => 'fleet/cells/order_status.html.twig'])
            ->add('created', TwigColumn::class, ['field' => 'createdAt', 'label' => 'fleet.field.requested', 'orderable' => true, 'template' => 'fleet/cells/time.html.twig'])
            ->add('finished', TwigColumn::class, ['field' => 'finishedAt', 'label' => 'fleet.field.finished', 'orderable' => false, 'template' => 'fleet/cells/time.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListAgentOrders => new ListAgentOrders($agentId, $page)]);
    }
}
