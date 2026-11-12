<?php

declare(strict_types=1);

namespace Exposure\UI\DataTable;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\FindingCriteria;
use Exposure\Application\Query\ListTeamFindings;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The findings of a team, a project or an environment, filtered, with their subject (ListTeamFindings judges).
 */
final class TeamFindingsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['teamId', 'scope', 'criteria'])->setDefaults(['id' => null]);
        /** @var array{teamId: string, scope: ExposureScope, id: ?string, criteria: FindingCriteria} $o */
        $o = $resolver->resolve($options);

        $dataTable
            ->setName('findings')
            ->add('subject', TwigColumn::class, ['field' => 'subjectName', 'label' => 'exposure.field.subject', 'orderable' => false, 'template' => 'exposure/cells/finding_subject.html.twig'])
            ->add('title', TwigColumn::class, ['field' => 'title', 'label' => 'exposure.field.finding', 'orderable' => false, 'template' => 'exposure/cells/finding_title.html.twig'])
            ->add('severity', TwigColumn::class, ['field' => 'severity', 'label' => 'exposure.field.severity', 'orderable' => false, 'template' => 'exposure/cells/finding_severity.html.twig'])
            ->add('versions', TwigColumn::class, ['field' => 'installed', 'label' => 'exposure.field.versions', 'orderable' => false, 'template' => 'exposure/cells/finding_versions.html.twig'])
            ->add('status', TwigColumn::class, ['field' => 'status', 'label' => 'exposure.field.status', 'orderable' => false, 'template' => 'exposure/cells/finding_status.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListTeamFindings => new ListTeamFindings($o['teamId'], $o['scope'], $o['id'], $o['criteria'], $page)]);
    }
}
