<?php

declare(strict_types=1);

namespace Shared\UI\Twig;

use Omines\DataTablesBundle\DataTable;
use Shared\UI\DataTable\DataTableView;
use Twig\Attribute\AsTwigFunction;

final readonly class DataTableExtension
{
    public function __construct(private DataTableView $view)
    {
    }

    /**
     * @return array{name: string, columns: list<array{name: string, label: string, orderable: bool, className: string}>, language: array<string, string>, pageLength: int}
     */
    #[AsTwigFunction('datatable_config')]
    public function config(DataTable $table): array
    {
        $pageLength = $table->getOption('pageLength');

        return [
            'name' => $table->getName(),
            'columns' => $this->view->columns($table),
            'language' => $this->view->language(),
            'pageLength' => \is_int($pageLength) ? $pageLength : 25,
        ];
    }
}
