<?php

declare(strict_types=1);

namespace Notification\UI\DataTable;

use Notification\Application\Query\ListRules;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\DataTableView;
use Shared\UI\DataTable\QueryBusAdapter;

final class RulesTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $teamId = $options['teamId'] ?? null;
        $projectId = $options['projectId'] ?? null;
        if (!\is_string($teamId) || (null !== $projectId && !\is_string($projectId))) {
            throw new \InvalidArgumentException('Options "teamId" and "projectId" are required.');
        }

        $dataTable
            ->setName('rules')
            ->add('name', TwigColumn::class, ['field' => 'name', 'label' => 'notification.field.name', 'orderable' => false, 'template' => 'notification/cells/rule_name.html.twig'])
            ->add('events', TwigColumn::class, ['field' => 'events', 'label' => 'notification.field.events', 'orderable' => false, 'template' => 'notification/cells/rule_events.html.twig'])
            ->add('thresholds', TwigColumn::class, ['field' => 'id', 'label' => 'notification.field.thresholds', 'orderable' => false, 'template' => 'notification/cells/rule_thresholds.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('targets', TwigColumn::class, ['field' => 'id', 'label' => 'notification.field.targets', 'orderable' => false, 'template' => 'notification/cells/rule_targets.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('actions', TwigColumn::class, ['field' => 'id', 'label' => 'admin.users.actions', 'orderable' => false, 'template' => 'notification/cells/rule_actions.html.twig', 'className' => 'text-end'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListRules => new ListRules($teamId, $projectId, $page)]);
    }
}
