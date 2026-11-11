<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryTokens implements EnrollmentTokenRepository
{
    /** @var array<string, EnrollmentToken> */
    public array $tokens = [];

    /** @var list<string> */
    public array $hashLookups = [];

    /** @var array<string, string> id => hash */
    private array $hashes = [];

    public function find(EnrollmentTokenId $id): ?EnrollmentToken
    {
        return $this->tokens[$id->toString()] ?? null;
    }

    public function findByHash(string $tokenHash): ?EnrollmentToken
    {
        $this->hashLookups[] = $tokenHash;
        $id = array_search($tokenHash, $this->hashes, true);

        return false === $id ? null : $this->tokens[$id];
    }

    public function add(EnrollmentToken $token): void
    {
        $this->addWithHash($token, 'hash-'.$token->id()->toString());
    }

    public function addWithHash(EnrollmentToken $token, string $hash): void
    {
        $this->tokens[$token->id()->toString()] = $token;
        $this->hashes[$token->id()->toString()] = $hash;
    }

    public function countActiveReusable(Uuid $teamId, \DateTimeImmutable $now): int
    {
        return \count(array_filter($this->tokens, static fn (EnrollmentToken $t): bool => $t->teamId()->equals($teamId) && $t->isReusable() && $t->isUsableAt($now)));
    }

    public function hasActiveForEnvironment(Uuid $environmentId, \DateTimeImmutable $now): bool
    {
        return [] !== array_filter($this->tokens, static fn (EnrollmentToken $t): bool => true === $t->environmentId()?->equals($environmentId) && $t->isUsableAt($now));
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->tokens = array_filter($this->tokens, static fn (EnrollmentToken $x): bool => !$x->teamId()->equals($teamId));
    }
}
