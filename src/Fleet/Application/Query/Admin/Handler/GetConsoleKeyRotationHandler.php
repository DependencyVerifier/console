<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Admin\Handler;

use Fleet\Application\Port\ConsoleSigner;
use Fleet\Application\Query\Admin\GetConsoleKeyRotation;
use Fleet\Application\Query\View\ConsoleKeyRotation;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Repository\AgentRepository;
use Shared\Application\Bus\QueryHandler;

final readonly class GetConsoleKeyRotationHandler implements QueryHandler
{
    public function __construct(private ConsoleSigner $signer, private AgentRepository $agents)
    {
    }

    public function __invoke(GetConsoleKeyRotation $query): ConsoleKeyRotation
    {
        $next = $this->signer->nextKey()?->fingerprint();
        $approved = $this->agents->approved();

        return new ConsoleKeyRotation(
            $this->signer->publicKey()->fingerprint(),
            $next,
            \count($approved),
            \count(array_filter($approved, static fn (Agent $a): bool => null !== $next && $a->pinnedConsoleKey() === $next)),
        );
    }
}
