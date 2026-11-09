<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

use function Symfony\Component\String\b;

/**
 * Typed, bounded audit details: scalar values only, keys whitelisted by the action, 4 KiB at most once encoded.
 * There is no way to put a request body, a password or a token in it by accident.
 */
final readonly class AuditContext
{
    public const int MAX_BYTES = 4096;

    /**
     * @param array<string, string|int|bool> $values
     */
    private function __construct(public array $values)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, string|int|bool> $values
     */
    public static function for(AuditAction $action, array $values): self
    {
        foreach (array_keys($values) as $key) {
            if (!$action->allows($key)) {
                throw new \InvalidArgumentException(\sprintf('Context key "%s" is not allowed for "%s".', $key, $action->name));
            }
        }

        $context = new self($values);
        if (b($context->toJson())->length() > self::MAX_BYTES) {
            throw new \LengthException(\sprintf('Audit context exceeds %d bytes.', self::MAX_BYTES));
        }

        return $context;
    }

    public function toJson(): string
    {
        return json_encode((object) $this->values, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
