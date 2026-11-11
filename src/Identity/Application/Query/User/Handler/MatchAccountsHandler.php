<?php

declare(strict_types=1);

namespace Identity\Application\Query\User\Handler;

use Identity\Application\Query\User\UserDirectory;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Identity\MatchAccounts;

use function Symfony\Component\String\u;

/**
 * Identity's published query: ids of the accounts matching a term. The caller decides which of them the actor may see.
 */
final readonly class MatchAccountsHandler implements QueryHandler
{
    public function __construct(private UserDirectory $directory)
    {
    }

    /**
     * @return list<string>
     */
    public function __invoke(MatchAccounts $query): array
    {
        $term = u($query->term)->trim()->toString();

        return '' === $term ? [] : $this->directory->matchIds($term, MatchAccounts::LIMIT);
    }
}
