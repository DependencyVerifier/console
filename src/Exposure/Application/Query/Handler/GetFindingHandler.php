<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\GetFinding;
use Exposure\Application\Query\View\AcceptanceView;
use Exposure\Application\Query\View\FindingView;
use Exposure\Application\Security\FindingAccess;
use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Model\SubjectType;
use Exposure\Domain\Repository\RiskAcceptanceRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Identity\FindAccounts;
use Symfony\Component\Uid\Uuid;

final readonly class GetFindingHandler implements QueryHandler
{
    public function __construct(private FindingAccess $access, private FindingReadModel $read, private RiskAcceptanceRepository $acceptances, private QueryBus $queries, private ClockInterface $clock)
    {
    }

    public function __invoke(GetFinding $query): FindingView
    {
        $today = $this->clock->now();
        $finding = Uuid::isValid($query->findingId) ? $this->read->find($query->findingId, $today) : null;
        if (null === $finding || $finding->teamId !== $query->teamId) {
            throw ResourceNotFound::of('finding');
        }

        $project = $this->access->project($finding->teamId, SubjectType::from($finding->subjectType), $finding->subjectId);
        $history = $this->acceptances->ofFinding(Uuid::fromString($finding->id));
        $accounts = [] === $history ? [] : $this->queries->ask(new FindAccounts(array_values(array_unique(array_map(static fn (RiskAcceptance $a): string => $a->acceptedBy()->toRfc4122(), $history)))));
        $acceptances = array_map(static fn (RiskAcceptance $a): AcceptanceView => new AcceptanceView(
            $a->justification(), ($accounts[$a->acceptedBy()->toRfc4122()] ?? null)->email ?? '—', $a->acceptedAt(), $a->expiresOn(), $a->revokedAt(), $a->isActiveOn($today),
        ), $history);

        return new FindingView($finding, $acceptances, $this->access->mayAccept($finding->teamId, $project));
    }
}
