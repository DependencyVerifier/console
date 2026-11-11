<?php

declare(strict_types=1);

namespace Inventory\UI\DataTable;

use Inventory\Application\Query\Team\ListApplications;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The applications of a team (of a project, not attached yet, of a host, of an image); ListApplications decides what
 * the actor sees.
 */
final class ApplicationsTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired('teamId')->setAllowedTypes('teamId', 'string');
        $resolver->setDefaults(['projectId' => null, 'unattached' => false, 'hostId' => null, 'imageId' => null, 'name' => 'applications']);
        /** @var array{teamId: string, projectId: ?string, unattached: bool, hostId: ?string, imageId: ?string, name: string} $o */
        $o = $resolver->resolve($options);

        $dataTable
            ->setName($o['name'])
            ->add('name', TwigColumn::class, ['field' => 'subjectKey', 'label' => 'inventory.field.application', 'orderable' => true, 'template' => 'inventory/cells/app_name.html.twig'])
            ->add('host', TwigColumn::class, ['field' => 'hostname', 'label' => 'inventory.field.host', 'orderable' => true, 'template' => 'inventory/cells/app_host.html.twig'])
            ->add('environment', TwigColumn::class, ['field' => 'environment', 'label' => 'inventory.field.environment', 'orderable' => false, 'template' => 'inventory/cells/app_environment.html.twig'])
            ->add('packages', TwigColumn::class, ['field' => 'packages', 'label' => 'inventory.field.packages', 'orderable' => false, 'template' => 'fleet/cells/number.html.twig'])
            ->add('last_seen', TwigColumn::class, ['field' => 'lastSeenAt', 'label' => 'inventory.field.last_seen', 'orderable' => true, 'template' => 'fleet/cells/time.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListApplications => new ListApplications($o['teamId'], $page, $o['projectId'], $o['unattached'], $o['hostId'], $o['imageId'])]);
    }
}
