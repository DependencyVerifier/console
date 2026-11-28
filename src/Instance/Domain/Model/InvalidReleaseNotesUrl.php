<?php

declare(strict_types=1);

namespace Instance\Domain\Model;

/**
 * A misconfigured base URL of the release notes (a DI parameter): a configuration error, not a business rule.
 */
final class InvalidReleaseNotesUrl extends \InvalidArgumentException
{
}
