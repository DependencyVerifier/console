<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team;

use Fleet\Application\Query\View\TokenRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * @implements Query<Page<TokenRow>>
 */
final readonly class ListEnrollmentTokens implements Query
{
    public function __construct(public string $teamId, public PageRequest $page = new PageRequest())
    {
    }
}
