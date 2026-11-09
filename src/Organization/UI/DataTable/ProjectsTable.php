<?php

declare(strict_types=1);

namespace Organization\UI\DataTable;

use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Organization\Application\Query\Project\ListProjects;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped), with `teamId` in their
 * context for the actions.
 */
final class ProjectsTable implements DataTableTypeInterface
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
            ->setName('projects')
            ->add('name', TwigColumn::class, [...['label' => 'organization.field.name', 'orderable' => true, 'template' => 'organization/cells/project_name.html.twig'], 'globalSearchable' => false])
            ->add('environments', TwigColumn::class, [...['label' => 'organization.field.environments', 'orderable' => false, 'template' => 'organization/cells/number.html.twig'], 'globalSearchable' => false])
            ->add('members', TwigColumn::class, [...['label' => 'organization.field.members', 'orderable' => false, 'template' => 'organization/cells/number.html.twig'], 'globalSearchable' => false])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListProjects => new ListProjects($id, $page)]);
    }
}
