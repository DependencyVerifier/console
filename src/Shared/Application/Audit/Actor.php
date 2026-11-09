<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

use Symfony\Component\Uid\Uuid;

/**
 * Who acted. Named constructors instead of a nullable id: a user always has an id, the others never.
 */
final readonly class Actor
{
    private function __construct(public ActorType $type, private ?Uuid $id)
    {
    }

    public static function user(Uuid $id): self
    {
        return new self(ActorType::User, $id);
    }

    public static function cli(): self
    {
        return new self(ActorType::Cli, null);
    }

    public static function system(): self
    {
        return new self(ActorType::System, null);
    }

    public static function anonymous(): self
    {
        return new self(ActorType::Anonymous, null);
    }

    public function isUser(): bool
    {
        return ActorType::User === $this->type;
    }

    /**
     * The user id, for a user actor only.
     */
    public function userId(): Uuid
    {
        return $this->id ?? throw new \LogicException(\sprintf('A "%s" actor has no id.', $this->type->value));
    }

    public function idForStorage(): ?string
    {
        return $this->id?->toRfc4122();
    }
}
