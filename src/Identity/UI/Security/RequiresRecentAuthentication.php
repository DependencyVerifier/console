<?php

declare(strict_types=1);

namespace Identity\UI\Security;

/**
 * Sensitive action (decision 8): the last full authentication must be less than 10 minutes old, else the user goes
 * through /reauthenticate and comes back to this route (by name, never a client-supplied URL). Protects the UI only:
 * a future API entry point needs its own check.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresRecentAuthentication
{
    /**
     * @param string $returnRoute full name of the GET route to come back to after /reauthenticate
     */
    public function __construct(public string $returnRoute)
    {
    }
}
