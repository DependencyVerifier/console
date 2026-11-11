<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

/**
 * A JSON request body of protocol v1, read field by field: unknown fields are ignored (§2), a wrong type or a
 * missing required field is `invalid_payload`, any `protocol_version` but 1 is `unsupported_protocol_version`.
 */
final readonly class AgentPayload
{
    public const int MAX_BYTES = 1048576;

    /**
     * @param array<string, mixed> $fields
     */
    private function __construct(private array $fields)
    {
    }

    public static function parse(#[\SensitiveParameter] string $body): self
    {
        try {
            $fields = json_decode($body, true, 16, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'The body is not JSON.');
        }

        if (!\is_array($fields) || array_is_list($fields)) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'The body is not a JSON object.');
        }

        /** @var array<string, mixed> $fields */
        $payload = new self($fields);
        if (1 !== ($fields['protocol_version'] ?? null)) {
            throw new ProtocolRefusal(Problem::UnsupportedProtocolVersion, 'This console speaks protocol version 1.');
        }

        return $payload;
    }

    public function string(string $name): string
    {
        return $this->optionalString($name) ?? throw self::invalid($name);
    }

    public function optionalString(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw self::invalid($name);
        }

        return $value;
    }

    public function int(string $name): int
    {
        $value = $this->fields[$name] ?? null;

        return \is_int($value) ? $value : throw self::invalid($name);
    }

    public function object(string $name): self
    {
        $value = $this->fields[$name] ?? null;
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw self::invalid($name);
        }

        /** @var array<string, mixed> $value */
        return new self($value);
    }

    public function optionalObject(string $name): ?self
    {
        return null === ($this->fields[$name] ?? null) ? null : $this->object($name);
    }

    /**
     * @return list<self>
     */
    public function objects(string $name): array
    {
        $value = $this->fields[$name] ?? [];
        if (!\is_array($value) || !array_is_list($value)) {
            throw self::invalid($name);
        }

        $objects = [];
        foreach ($value as $item) {
            if (!\is_array($item) || ([] !== $item && array_is_list($item))) {
                throw self::invalid($name);
            }

            /** @var array<string, mixed> $item */
            $objects[] = new self($item);
        }

        return $objects;
    }

    private static function invalid(string $name): ProtocolRefusal
    {
        return new ProtocolRefusal(Problem::InvalidPayload, \sprintf('Field "%s" is missing or has the wrong type.', $name));
    }
}
