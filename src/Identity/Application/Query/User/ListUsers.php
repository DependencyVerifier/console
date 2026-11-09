<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * @implements Query<Page<UserSummary>>
 */
final readonly class ListUsers implements Query
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
