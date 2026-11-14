<?php

declare(strict_types=1);

namespace Exposure\UI\DataTable;

use Exposure\Application\Query\ListFindings;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\DataTableView;
use Shared\UI\DataTable\QueryBusAdapter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The findings of an application or a host, open first and the most severe first (ListFindings judges the access).
 */
final class FindingsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['teamId', 'subjectType', 'subjectId']);
        /** @var array{teamId: string, subjectType: string, subjectId: string} $o */
        $o = $resolver->resolve($options);

        $dataTable
            ->setName('findings')
            ->add('title', TwigColumn::class, ['field' => 'title', 'label' => 'exposure.field.finding', 'orderable' => false, 'template' => 'exposure/cells/finding_title.html.twig'])
            ->add('severity', TwigColumn::class, ['field' => 'severity', 'label' => 'exposure.field.severity', 'orderable' => false, 'template' => 'exposure/cells/finding_severity.html.twig'])
            ->add('versions', TwigColumn::class, ['field' => 'installed', 'label' => 'exposure.field.versions', 'orderable' => false, 'template' => 'exposure/cells/finding_versions.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('status', TwigColumn::class, ['field' => 'status', 'label' => 'exposure.field.status', 'orderable' => false, 'template' => 'exposure/cells/finding_status.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListFindings => new ListFindings($o['teamId'], $o['subjectType'], $o['subjectId'], $page)]);
    }
}
