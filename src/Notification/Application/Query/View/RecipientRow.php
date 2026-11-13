<?php

declare(strict_types=1);

namespace Notification\Application\Query\View;

/**
 * A recipient of a project, as its managers see it: attached (its subscription) or invited (its invitation, pending).
 */
final readonly class RecipientRow
{
    public const string ATTACHED = 'attached';
    public const string INVITED = 'invited';

    public function __construct(public string $id, public string $state, public string $email, public string $frequency, public \DateTimeImmutable $since)
    {
    }
}
