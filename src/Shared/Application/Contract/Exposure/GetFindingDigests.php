<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Exposure;

use Shared\Application\Bus\Query;

/**
 * Published query of Exposure: what a notification says of findings of a team (no visibility check: the caller
 * filters by the projects each recipient sees).
 *
 * @implements Query<list<FindingDigest>>
 */
final readonly class GetFindingDigests implements Query
{
    /**
     * @param list<string> $findingIds
     */
    public function __construct(public string $teamId, public array $findingIds)
    {
    }
}
