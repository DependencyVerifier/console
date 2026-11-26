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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The findings of an application or a host, open first and the most severe first (ListFindings judges the access);
 * the fixed ones only with `?fixed=1` (showsFixed).
 */
final class FindingsTable implements DataTableTypeInterface
{
    /**
     * Whether the page asks for the fixed findings too. The raw value: `query->get()` would answer 400 to `?fixed[]=1`,
     * and anything but `1` is the default.
     */
    public static function showsFixed(Request $request): bool
    {
        return '1' === ($request->query->all()['fixed'] ?? null);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['teamId', 'subjectType', 'subjectId']);
        $resolver->setDefault('withFixed', false);
        $resolver->setAllowedTypes('withFixed', 'bool');
        /** @var array{teamId: string, subjectType: string, subjectId: string, withFixed: bool} $o */
        $o = $resolver->resolve($options);

        $dataTable
            ->setName('findings')
            ->add('title', TwigColumn::class, ['field' => 'title', 'label' => 'exposure.field.finding', 'orderable' => false, 'template' => 'exposure/cells/finding_title.html.twig'])
            ->add('severity', TwigColumn::class, ['field' => 'severity', 'label' => 'exposure.field.severity', 'orderable' => false, 'template' => 'exposure/cells/finding_severity.html.twig'])
            ->add('versions', TwigColumn::class, ['field' => 'installed', 'label' => 'exposure.field.versions', 'orderable' => false, 'template' => 'exposure/cells/finding_versions.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('status', TwigColumn::class, ['field' => 'status', 'label' => 'exposure.field.status', 'orderable' => false, 'template' => 'exposure/cells/finding_status.html.twig', 'className' => DataTableView::SECONDARY])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListFindings => new ListFindings($o['teamId'], $o['subjectType'], $o['subjectId'], withFixed: $o['withFixed'], page: $page)]);
    }
}
