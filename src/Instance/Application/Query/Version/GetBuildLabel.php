<?php

declare(strict_types=1);

namespace Instance\Application\Query\Version;

use Instance\Domain\Model\BuildLabel;
use Shared\Application\Bus\Query;

/**
 * The label of the running build, for every signed-in user (the footer): the version is public in the image and in
 * the release notes, and it is never shown on the public pages.
 *
 * @implements Query<BuildLabel>
 */
final readonly class GetBuildLabel implements Query
{
}
