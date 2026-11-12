<?php

declare(strict_types=1);

namespace Inventory\UI\DataTable;

use Inventory\Application\Query\Team\ListInventoryHistory;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The inventories that saw a host or an application, the latest first (ListInventoryHistory judges the access).
 */
final class HistoryTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['teamId', 'of', 'id']);
        /** @var array{teamId: string, of: string, id: string} $o */
        $o = $resolver->resolve($options);

        $dataTable
            ->setName('history')
            ->add('taken_at', TwigColumn::class, ['field' => 'takenAt', 'label' => 'inventory.field.inventoried_at', 'orderable' => false, 'template' => 'fleet/cells/time.html.twig'])
            ->add('subject', TwigColumn::class, ['field' => 'subject', 'label' => 'inventory.field.snapshot', 'orderable' => false, 'template' => 'inventory/cells/history_subject.html.twig'])
            ->add('trigger', TwigColumn::class, ['field' => 'trigger', 'label' => 'inventory.field.trigger', 'orderable' => false, 'template' => 'inventory/cells/history_trigger.html.twig'])
            ->add('digest', TwigColumn::class, ['field' => 'digest', 'label' => 'inventory.field.digest', 'orderable' => false, 'template' => 'inventory/cells/history_digest.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListInventoryHistory => new ListInventoryHistory($o['teamId'], $o['of'], $o['id'], $page)]);
    }
}
