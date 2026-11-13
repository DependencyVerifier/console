<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * The filters of a list of findings, from the page: an unknown value means no filter, never an error.
 */
final readonly class FindingCriteria
{
    public const array KINDS = ['vulnerability', 'eol', 'outdated'];
    public const array SEVERITIES = ['critical', 'high', 'medium', 'low'];
    public const array STATUSES = ['open', 'accepted', 'fixed', 'all'];

    public ?string $kind;
    public ?string $severity;
    public string $status;
    public ?string $projectId;
    public ?string $environmentId;
    public ?string $hostId;
    public ?string $advisory;

    /**
     * @param ?string $hostId     the host itself and the applications running on it
     * @param ?bool   $production in production only (true), out of it only (false), both (null)
     * @param ?string $advisory   the findings of this advisory (an OSV id or one of its aliases)
     */
    public function __construct(
        ?string $kind = null,
        ?string $severity = null,
        ?string $status = null,
        ?string $projectId = null,
        ?string $environmentId = null,
        ?string $hostId = null,
        public ?bool $production = null,
        ?string $advisory = null,
    ) {
        $this->kind = \in_array($kind, self::KINDS, true) ? $kind : null;
        $this->severity = \in_array($severity, self::SEVERITIES, true) ? $severity : null;
        $this->status = \in_array($status, self::STATUSES, true) ? $status : 'open';
        $this->projectId = self::id($projectId);
        $this->environmentId = self::id($environmentId);
        $this->hostId = self::id($hostId);
        $this->advisory = null !== $advisory && [] !== u($advisory)->match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/') ? $advisory : null;
    }

    private static function id(?string $id): ?string
    {
        return null !== $id && Uuid::isValid($id) ? $id : null;
    }
}
