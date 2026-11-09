<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Shared\Application\Bus\Query;
use Shared\Application\Bus\QueryBus;

/**
 * Answers each query class with a fixed result (a closure receives the query).
 */
final class FixedQueries implements QueryBus
{
    /** @var list<object> */
    public array $asked = [];

    /**
     * @param array<class-string, \Closure(object): mixed> $answers
     */
    public function __construct(private readonly array $answers)
    {
    }

    public function ask(Query $query): mixed
    {
        $this->asked[] = $query;

        return ($this->answers[$query::class] ?? throw new \LogicException('Unexpected '.$query::class))($query);
    }
}
