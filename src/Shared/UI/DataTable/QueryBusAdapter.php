<?php

declare(strict_types=1);

namespace Shared\UI\DataTable;

use Omines\DataTablesBundle\Adapter\AbstractAdapter;
use Omines\DataTablesBundle\Adapter\AdapterQuery;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Shared\Application\Bus\Query;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\String\u;

/**
 * DataTables server-side processing through the query bus: the table state (offset, length, first order column,
 * global search) becomes a PageRequest and the query handler stays the only judge of scope and permission (its
 * refusals are audited once by AuditDeniedQueryMiddleware). Rows are the handler's view objects; a column reads the
 * public property named by its `field` (or its name).
 */
final class QueryBusAdapter extends AbstractAdapter
{
    /** @var \Closure(PageRequest): Query<Page<object>> */
    private \Closure $query;

    public function __construct(private readonly QueryBus $queries)
    {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function configure(array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired('query')->setAllowedTypes('query', \Closure::class);
        /** @var array{query: \Closure(PageRequest): Query<Page<object>>} $resolved */
        $resolved = $resolver->resolve($options);
        $this->query = $resolved['query'];
    }

    protected function prepareQuery(AdapterQuery $query): void
    {
        $state = $query->getState();
        $order = $state->getOrderBy()[0] ?? null;
        $sort = null === $order ? null : $order[0]->getName();
        $descending = null !== $order && u($order[1])->lower()->equalsTo('desc');

        $page = $this->queries->ask(($this->query)(new PageRequest($state->getStart(), $state->getLength() ?? PageRequest::MAX_LIMIT, $sort, $descending, $state->getGlobalSearch())));
        $query->setTotalRows($page->total)->setFilteredRows($page->filtered);
        $query->set('items', $page->items);
    }

    protected function mapPropertyPath(AdapterQuery $query, AbstractColumn $column): string
    {
        return $column->getField() ?? $column->getName();
    }

    /**
     * @return \Generator<int, mixed[]>
     */
    protected function getResults(AdapterQuery $query): \Traversable
    {
        $items = $query->get('items', []);
        foreach (\is_array($items) ? $items : [] as $item) {
            /** @var mixed[] $row a view object, read through the property accessor */
            $row = $item;
            yield $row;
        }
    }
}
