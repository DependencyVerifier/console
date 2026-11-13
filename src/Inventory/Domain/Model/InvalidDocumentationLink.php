<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A documentation link that is not an http(s) URL, carries credentials, is too long, or too many links.
 */
final class InvalidDocumentationLink extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('invalid_documentation_link', $detail);
    }
}
