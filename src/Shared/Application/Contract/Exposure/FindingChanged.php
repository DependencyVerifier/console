<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Exposure;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Exposure: a finding opened, opened again, was fixed, or (an end of life) came closer. `at`
 * (ATOM) tells two occurrences of the same change apart: a notification is sent once per occurrence.
 */
final readonly class FindingChanged implements DomainEvent
{
    public const string OPENED = 'opened';
    public const string REOPENED = 'reopened';
    public const string FIXED = 'fixed';
    public const string ESCALATED = 'escalated';

    public function __construct(public string $findingId, public string $teamId, public string $change, public string $at)
    {
    }
}
