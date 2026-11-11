<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog;

use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * Keyset pagination position `(occurred_at, id)`, decoded once and validated: a timestamp and a UUID, nothing else.
 */
final readonly class AuditCursor
{
    private function __construct(public \DateTimeImmutable $occurredAt, public string $id)
    {
    }

    public static function at(\DateTimeImmutable $occurredAt, string $id): self
    {
        if (!Uuid::isValid($id)) {
            throw new InvalidAuditCursor();
        }

        return new self($occurredAt, $id);
    }

    public static function decode(string $encoded): self
    {
        $raw = base64_decode(u($encoded)->replace('-', '+')->replace('_', '/')->toString(), true);
        // Raw bytes, possibly not UTF-8 (a forged cursor): split as bytes, then the timestamp and UUID checks decide.
        $parts = false === $raw ? [] : b($raw)->split('|');
        if (2 !== \count($parts)) {
            throw new InvalidAuditCursor();
        }

        $occurredAt = \DateTimeImmutable::createFromFormat('U.u', $parts[0]->toString());
        if (false === $occurredAt) {
            throw new InvalidAuditCursor();
        }

        return self::at($occurredAt, $parts[1]->toString());
    }

    public function encode(): string
    {
        return u(base64_encode($this->occurredAt->format('U.u').'|'.$this->id))->replace('+', '-')->replace('/', '_')->toString();
    }
}
